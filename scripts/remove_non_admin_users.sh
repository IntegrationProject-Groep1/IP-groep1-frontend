#!/usr/bin/env bash
set -euo pipefail

# Removes all Drupal users except uid 0/1 and users with role 'administrator'.
# Safe by default: preview mode unless --apply is passed with confirmation text.

COMPOSE_FILES=("docker-compose.yml")
if [[ -f "docker-compose.local.yml" ]]; then
  COMPOSE_FILES+=("docker-compose.local.yml")
fi

DB_SERVICE="frontend_db"
DB_NAME="drupal"
DB_USER="drupal_user"
DB_PASS="${DRUPAL_DB_PASS:-}"
APPLY=0
CONFIRM_TEXT=""
REQUIRED_CONFIRM="DELETE-NON-ADMIN-USERS"

usage() {
  cat <<'EOF'
Usage:
  scripts/remove_non_admin_users.sh [options]

Options:
  --apply                     Execute deletion (default is preview only)
  --confirm TEXT              Required with --apply: DELETE-NON-ADMIN-USERS
  --db-service NAME           Docker compose DB service (default: frontend_db)
  --db-name NAME              Database name (default: drupal)
  --db-user NAME              Database user (default: drupal_user)
  --db-pass VALUE             Database password (or set DRUPAL_DB_PASS)
  --compose-file PATH         Add compose file (can be used multiple times)
  -h, --help                  Show this help

Examples:
  scripts/remove_non_admin_users.sh
  scripts/remove_non_admin_users.sh --apply --confirm DELETE-NON-ADMIN-USERS
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply)
      APPLY=1
      shift
      ;;
    --confirm)
      CONFIRM_TEXT="${2:-}"
      shift 2
      ;;
    --db-service)
      DB_SERVICE="${2:-}"
      shift 2
      ;;
    --db-name)
      DB_NAME="${2:-}"
      shift 2
      ;;
    --db-user)
      DB_USER="${2:-}"
      shift 2
      ;;
    --db-pass)
      DB_PASS="${2:-}"
      shift 2
      ;;
    --compose-file)
      COMPOSE_FILES+=("${2:-}")
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$DB_PASS" ]]; then
  if [[ -f ".env" ]]; then
    DB_PASS="$(grep -E '^DRUPAL_DB_PASS=' .env | head -n1 | cut -d'=' -f2- || true)"
  fi
fi

if [[ -z "$DB_PASS" ]]; then
  echo "ERROR: DRUPAL_DB_PASS not found. Set --db-pass or DRUPAL_DB_PASS/.env." >&2
  exit 1
fi

if [[ $APPLY -eq 1 && "$CONFIRM_TEXT" != "$REQUIRED_CONFIRM" ]]; then
  echo "ERROR: --apply requires --confirm $REQUIRED_CONFIRM" >&2
  exit 1
fi

compose_cmd=(docker compose)
for f in "${COMPOSE_FILES[@]}"; do
  compose_cmd+=( -f "$f" )
done

run_sql() {
  local sql="$1"
  "${compose_cmd[@]}" exec -T "$DB_SERVICE" mysql \
    "-u${DB_USER}" "-p${DB_PASS}" -D "$DB_NAME" -e "$sql"
}

preview_sql="
CREATE TEMPORARY TABLE keep_users (uid INT PRIMARY KEY);
INSERT IGNORE INTO keep_users (uid) VALUES (0), (1);
INSERT IGNORE INTO keep_users (uid)
SELECT DISTINCT ur.entity_id
FROM user__roles ur
WHERE ur.roles_target_id = 'administrator';

SELECT ufd.uid, ufd.name, ufd.mail,
       COALESCE(GROUP_CONCAT(ur.roles_target_id), '-') AS roles
FROM users_field_data ufd
LEFT JOIN user__roles ur ON ur.entity_id = ufd.uid
GROUP BY ufd.uid, ufd.name, ufd.mail
ORDER BY ufd.uid;

SELECT 'users_to_delete' AS metric, COUNT(*) AS total
FROM users_field_data ufd
WHERE ufd.uid NOT IN (SELECT uid FROM keep_users);
"

echo "Preview users and deletion impact..."
run_sql "$preview_sql"

if [[ $APPLY -eq 0 ]]; then
  echo "Preview complete. No users deleted."
  exit 0
fi

delete_sql="
START TRANSACTION;

CREATE TEMPORARY TABLE keep_users (uid INT PRIMARY KEY);
INSERT IGNORE INTO keep_users (uid) VALUES (0), (1);
INSERT IGNORE INTO keep_users (uid)
SELECT DISTINCT ur.entity_id
FROM user__roles ur
WHERE ur.roles_target_id = 'administrator';

DELETE FROM sessions WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM users_data WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM user__user_picture WHERE entity_id NOT IN (SELECT uid FROM keep_users);
DELETE FROM user__roles WHERE entity_id NOT IN (SELECT uid FROM keep_users);
DELETE FROM users_field_data WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM users WHERE uid NOT IN (SELECT uid FROM keep_users);

COMMIT;

SELECT 'remaining_users' AS metric, COUNT(*) AS total
FROM users_field_data;
"

echo "Deleting non-admin users..."
run_sql "$delete_sql"
echo "Done. All non-admin users removed."
