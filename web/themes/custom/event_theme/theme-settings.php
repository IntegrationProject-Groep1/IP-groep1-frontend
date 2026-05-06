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
    'event_theme_color_primary'  => [t('Primary color'),            '#2563eb'],
    'event_theme_color_accent'   => [t('Accent color'),             '#7c3aed'],
    'event_theme_color_bg'       => [t('Page background'),          '#f8fafc'],
    'event_theme_color_surface'  => [t('Card / surface color'),     '#ffffff'],
    'event_theme_color_nav_bg'   => [t('Navigation background'),    '#ffffff'],
    'event_theme_color_hero_bg'  => [t('Hero background'),          '#1e3a8a'],
    'event_theme_color_text'     => [t('Body text color'),          '#1e293b'],
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

  /* ── Hero section ── */
  $form['hero'] = [
    '#type'  => 'details',
    '#title' => t('Hero section (homepage)'),
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
    '#title'         => t('Footer copyright text'),
    '#default_value' => theme_get_setting('event_theme_footer_text') ?: '© Desideriushogeschool Event Platform. All rights reserved.',
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
