<?php

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 *
 * Adds admin-configurable settings to /admin/appearance/settings/event_theme.
 */
function event_theme_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {

  $form['event_theme_tabs'] = [
    '#type'  => 'vertical_tabs',
    '#title' => t('Theme options'),
  ];

  /* ── Identity ── */
  $form['identity'] = [
    '#type'  => 'details',
    '#title' => t('Identity'),
    '#group' => 'event_theme_tabs',
  ];
  $form['identity']['event_theme_site_name'] = [
    '#type'          => 'textfield',
    '#title'         => t('Site name override'),
    '#description'   => t('Leave empty to use the Drupal site name.'),
    '#default_value' => theme_get_setting('event_theme_site_name') ?? '',
  ];
  $form['identity']['event_theme_tagline'] = [
    '#type'          => 'textfield',
    '#title'         => t('Tagline / subtitle'),
    '#default_value' => theme_get_setting('event_theme_tagline') ?? '',
  ];

  /* ── Colors ── */
  $form['colors'] = [
    '#type'  => 'details',
    '#title' => t('Colors'),
    '#group' => 'event_theme_tabs',
  ];
  $colorSettings = [
    'event_theme_color_primary'  => [t('Primary color'),            '#1f3a8a'],
    'event_theme_color_accent'   => [t('Accent color'),             '#0e7c66'],
    'event_theme_color_bg'       => [t('Page background'),          '#f4f5fa'],
    'event_theme_color_surface'  => [t('Card / surface color'),     '#ffffff'],
    'event_theme_color_nav_bg'   => [t('Navigation background'),    '#0c1135'],
    'event_theme_color_hero_bg'  => [t('Hero background'),          '#0c1135'],
    'event_theme_color_text'     => [t('Body text color'),          '#0c1135'],
  ];
  foreach ($colorSettings as $key => [$label, $default]) {
    $form['colors'][$key] = [
      '#type'          => 'color',
      '#title'         => $label,
      '#default_value' => theme_get_setting($key) ?: $default,
    ];
  }

  /* ── Typography ── */
  $form['typography'] = [
    '#type'  => 'details',
    '#title' => t('Typography'),
    '#group' => 'event_theme_tabs',
  ];
  $form['typography']['event_theme_font'] = [
    '#type'          => 'select',
    '#title'         => t('Font family'),
    '#options'       => [
      'Inter'       => 'Inter',
      'Roboto'      => 'Roboto',
      'Poppins'     => 'Poppins',
      'Open Sans'   => 'Open Sans',
      'Lato'        => 'Lato',
      'Nunito'      => 'Nunito',
      'Montserrat'  => 'Montserrat',
    ],
    '#default_value' => theme_get_setting('event_theme_font') ?: 'Inter',
  ];
  $form['typography']['event_theme_border_radius'] = [
    '#type'          => 'select',
    '#title'         => t('Corner radius style'),
    '#options'       => [
      'sharp'   => t('Sharp (0 px)'),
      'default' => t('Rounded (8 px)'),
      'large'   => t('Very rounded (16 px)'),
      'pill'    => t('Pill (9999 px)'),
    ],
    '#default_value' => theme_get_setting('event_theme_border_radius') ?: 'default',
  ];

  /* ── Frontpage content ── */
  $form['frontpage'] = [
    '#type'  => 'details',
    '#title' => t('Frontpage content'),
    '#group' => 'event_theme_tabs',
  ];

  // Status badge
  $form['frontpage']['event_theme_lp_status_label'] = [
    '#type'          => 'textfield',
    '#title'         => t('Status badge (e.g. "Registratie is open")'),
    '#default_value' => theme_get_setting('event_theme_lp_status_label') ?: 'Registratie is open',
  ];

  // Hero title lines
  $form['frontpage']['event_theme_lp_hero_line1'] = [
    '#type'          => 'textfield',
    '#title'         => t('Hero — regel 1'),
    '#default_value' => theme_get_setting('event_theme_lp_hero_line1') ?: 'Drie dagen.',
  ];
  $form['frontpage']['event_theme_lp_hero_line2'] = [
    '#type'          => 'textfield',
    '#title'         => t('Hero — regel 2'),
    '#default_value' => theme_get_setting('event_theme_lp_hero_line2') ?: 'Twaalf podia.',
  ];
  $form['frontpage']['event_theme_lp_hero_line3'] = [
    '#type'          => 'textfield',
    '#title'         => t('Hero — regel 3 (geaccentueerd)'),
    '#default_value' => theme_get_setting('event_theme_lp_hero_line3') ?: 'Één geïntegreerde ervaring.',
  ];
  $form['frontpage']['event_theme_lp_hero_description'] = [
    '#type'          => 'textarea',
    '#title'         => t('Hero — omschrijving'),
    '#rows'          => 3,
    '#default_value' => theme_get_setting('event_theme_lp_hero_description') ?: 'Een jaarlijkse bijeenkomst voor engineers, designers en operators die integratiesystemen bouwen. Workshops, keynotes en late-night demo\'s op twaalf locaties op de Desiderius Campus.',
  ];

  // Event metadata
  $form['frontpage']['event_theme_lp_event_date'] = [
    '#type'          => 'textfield',
    '#title'         => t('Eventdatum'),
    '#default_value' => theme_get_setting('event_theme_lp_event_date') ?: '12 – 14 mei 2026',
  ];
  $form['frontpage']['event_theme_lp_event_location'] = [
    '#type'          => 'textfield',
    '#title'         => t('Eventlocatie'),
    '#default_value' => theme_get_setting('event_theme_lp_event_location') ?: 'Desiderius Campus · Brussel',
  ];
  $form['frontpage']['event_theme_lp_event_tracks'] = [
    '#type'          => 'textfield',
    '#title'         => t('Tracks / sessies samenvatting'),
    '#default_value' => theme_get_setting('event_theme_lp_event_tracks') ?: '12 podia · 84 sessies',
  ];

  // CTA buttons
  $form['frontpage']['event_theme_lp_cta1_label'] = [
    '#type'          => 'textfield',
    '#title'         => t('CTA knop 1 — label'),
    '#default_value' => theme_get_setting('event_theme_lp_cta1_label') ?: 'Registreer voor het event',
  ];
  $form['frontpage']['event_theme_lp_cta2_label'] = [
    '#type'          => 'textfield',
    '#title'         => t('CTA knop 2 — label'),
    '#default_value' => theme_get_setting('event_theme_lp_cta2_label') ?: 'Ik heb al een badge',
  ];

  // Stats strip
  $form['frontpage']['stats_heading'] = [
    '#type'   => 'markup',
    '#markup' => '<h3>' . t('Statistieken balk') . '</h3>',
  ];
  foreach ([
    ['event_theme_lp_stat1_num', t('Stat 1 — getal'), '2.400+'],
    ['event_theme_lp_stat1_lbl', t('Stat 1 — label'), 'Verwachte deelnemers'],
    ['event_theme_lp_stat2_num', t('Stat 2 — getal'), '84'],
    ['event_theme_lp_stat2_lbl', t('Stat 2 — label'), 'Sessies over drie dagen'],
    ['event_theme_lp_stat3_num', t('Stat 3 — getal'), '12'],
    ['event_theme_lp_stat3_lbl', t('Stat 3 — label'), 'Podia en labs op campus'],
    ['event_theme_lp_stat4_num', t('Stat 4 — getal'), '37'],
    ['event_theme_lp_stat4_lbl', t('Stat 4 — label'), 'Partnerorganisaties'],
  ] as [$key, $label, $default]) {
    $form['frontpage'][$key] = [
      '#type'          => 'textfield',
      '#title'         => $label,
      '#default_value' => theme_get_setting($key) ?: $default,
      '#size'          => 30,
    ];
  }

  // Program section
  $form['frontpage']['program_heading'] = [
    '#type'   => 'markup',
    '#markup' => '<h3>' . t('Programma sectie') . '</h3>',
  ];
  $form['frontpage']['event_theme_lp_program_eyebrow'] = [
    '#type'          => 'textfield',
    '#title'         => t('Programma — eyebrow tekst'),
    '#default_value' => theme_get_setting('event_theme_lp_program_eyebrow') ?: 'Dag één · Dinsdag',
  ];
  $form['frontpage']['event_theme_lp_program_title'] = [
    '#type'          => 'textfield',
    '#title'         => t('Programma — titel'),
    '#default_value' => theme_get_setting('event_theme_lp_program_title') ?: 'Een eventprogramma, geen conferentieagenda.',
  ];
  $form['frontpage']['event_theme_lp_program_description'] = [
    '#type'          => 'textarea',
    '#title'         => t('Programma — omschrijving'),
    '#rows'          => 2,
    '#default_value' => theme_get_setting('event_theme_lp_program_description') ?: 'Een voorproefje van wat er op het programma staat. Na registratie kun je je inschrijven voor sessies met nog beschikbare plaatsen.',
  ];

  // Footer
  $form['frontpage']['footer_heading'] = [
    '#type'   => 'markup',
    '#markup' => '<h3>' . t('Footer (frontpage)') . '</h3>',
  ];
  $form['frontpage']['event_theme_lp_footer_location'] = [
    '#type'          => 'textfield',
    '#title'         => t('Footer — locatie'),
    '#default_value' => theme_get_setting('event_theme_lp_footer_location') ?: 'Desiderius Campus · Brussel',
  ];
  $form['frontpage']['event_theme_lp_footer_date'] = [
    '#type'          => 'textfield',
    '#title'         => t('Footer — datum'),
    '#default_value' => theme_get_setting('event_theme_lp_footer_date') ?: '12 – 14 mei 2026',
  ];
  $form['frontpage']['event_theme_lp_footer_org'] = [
    '#type'          => 'textfield',
    '#title'         => t('Footer — organisatie'),
    '#default_value' => theme_get_setting('event_theme_lp_footer_org') ?: 'Een integratieproject van Desideriushogeschool',
  ];

  /* ── Hero section (dashboard) ── */
  $form['hero'] = [
    '#type'  => 'details',
    '#title' => t('Hero section (dashboard)'),
    '#group' => 'event_theme_tabs',
  ];
  $form['hero']['event_theme_hero_enabled'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Show hero section on the homepage'),
    '#default_value' => theme_get_setting('event_theme_hero_enabled') ?? 1,
  ];
  $form['hero']['event_theme_hero_title'] = [
    '#type'          => 'textfield',
    '#title'         => t('Hero title'),
    '#default_value' => theme_get_setting('event_theme_hero_title') ?: 'Desideriushogeschool Event Platform',
  ];
  $form['hero']['event_theme_hero_subtitle'] = [
    '#type'          => 'textfield',
    '#title'         => t('Hero subtitle'),
    '#default_value' => theme_get_setting('event_theme_hero_subtitle') ?: 'Discover, register and manage your sessions.',
  ];
  $form['hero']['event_theme_hero_cta_label'] = [
    '#type'          => 'textfield',
    '#title'         => t('Call-to-action button label'),
    '#default_value' => theme_get_setting('event_theme_hero_cta_label') ?: 'Register now',
  ];
  $form['hero']['event_theme_hero_cta_url'] = [
    '#type'          => 'textfield',
    '#title'         => t('Call-to-action button URL'),
    '#default_value' => theme_get_setting('event_theme_hero_cta_url') ?: '/register',
  ];

  /* ── Navigation ── */
  $form['navigation'] = [
    '#type'  => 'details',
    '#title' => t('Navigation'),
    '#group' => 'event_theme_tabs',
  ];
  $form['navigation']['event_theme_nav_show_register'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Show "Register" link in navigation'),
    '#default_value' => theme_get_setting('event_theme_nav_show_register') ?? 1,
  ];
  $form['navigation']['event_theme_nav_show_enroll'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Show "Enroll in sessions" link in navigation'),
    '#default_value' => theme_get_setting('event_theme_nav_show_enroll') ?? 1,
  ];

  /* ── Footer ── */
  $form['footer_settings'] = [
    '#type'  => 'details',
    '#title' => t('Footer'),
    '#group' => 'event_theme_tabs',
  ];
  $form['footer_settings']['event_theme_footer_text'] = [
    '#type'          => 'textfield',
    '#title'         => t('Copyright tekst'),
    '#default_value' => theme_get_setting('event_theme_footer_text') ?: '© Desideriushogeschool Event Platform. All rights reserved.',
  ];
  $form['footer_settings']['event_theme_footer_col2_title'] = [
    '#type'          => 'textfield',
    '#title'         => t('Kolom 2 — titel'),
    '#default_value' => theme_get_setting('event_theme_footer_col2_title') ?: 'Contact',
  ];
  $form['footer_settings']['event_theme_footer_address'] = [
    '#type'          => 'textfield',
    '#title'         => t('Adres'),
    '#default_value' => theme_get_setting('event_theme_footer_address') ?: 'Desiderius Campus, Brussel',
  ];
  $form['footer_settings']['event_theme_footer_email'] = [
    '#type'          => 'email',
    '#title'         => t('E-mailadres'),
    '#default_value' => theme_get_setting('event_theme_footer_email') ?: '',
  ];
  $form['footer_settings']['event_theme_footer_phone'] = [
    '#type'          => 'textfield',
    '#title'         => t('Telefoonnummer'),
    '#default_value' => theme_get_setting('event_theme_footer_phone') ?: '',
  ];
  $form['footer_settings']['event_theme_footer_col3_title'] = [
    '#type'          => 'textfield',
    '#title'         => t('Kolom 3 — titel (boven social links)'),
    '#default_value' => theme_get_setting('event_theme_footer_col3_title') ?: 'Volg ons',
  ];

  /* ── Social media ── */
  $form['social'] = [
    '#type'  => 'details',
    '#title' => t('Social media'),
    '#group' => 'event_theme_tabs',
  ];
  $socialLinks = [
    'event_theme_social_facebook'  => t('Facebook URL'),
    'event_theme_social_instagram' => t('Instagram URL'),
    'event_theme_social_linkedin'  => t('LinkedIn URL'),
    'event_theme_social_twitter'   => t('X / Twitter URL'),
  ];
  foreach ($socialLinks as $key => $label) {
    $form['social'][$key] = [
      '#type'          => 'url',
      '#title'         => $label,
      '#default_value' => theme_get_setting($key) ?: '',
    ];
  }
}
