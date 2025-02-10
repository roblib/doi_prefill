<?php

declare(strict_types=1);

namespace Drupal\doi_prefill\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\islandora\IslandoraUtils;

/**
 * Configure DOI Prefill settings for this site.
 */
final class DOIFieldSettingsForm extends ConfigFormBase {

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Islandora utility functions.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new DOIFieldSettingsForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   THe field manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, IslandoraUtils $utils) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->utils = $utils;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('islandora.utils'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'doi_prefill_doi_field_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['doi_prefill.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $doi_fields = [
      'title' => 'Title',
      'contributors' => 'Contributors',
      'publisher' => 'Publisher',
      'doi' => 'DOI',
      'genre' => 'Genre',
      'issue' => 'Issue',
      'volume' => 'Volume',
      'date_issued' => 'Date issued',
      'abstract' => 'Abstract',
      'host_title' => 'Host title',
      'date_online' => 'Date online',
      'page_range' => 'Page range',
      'series_issn' => 'Series ISSN',
    ];

    $config = $this->config('doi_prefill.settings');
    $fields = $this->entityFieldManager->getFieldDefinitions('node', 'islandora_object');
    $field_options = ['title' => 'Title'];
    foreach ($fields as $field) {
      if ($field instanceof FieldConfig) {
        $label = (string) $field->getLabel();
        $name = $field->getName();
        $field_options[$name] = $label;
      }
    }
    asort($field_options);
    $content_types = NodeType::loadMultiple();
    $destination_content_types = [];
    foreach ($content_types as $type) {
      if ($this->utils->isIslandoraType('node', $type->id())) {
        $destination_content_types[$type->id()] = $type->label();
      }
    }

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t("Choose content type for new node"),
      '#options' => $destination_content_types,
      '#default_value' => $config->get('content_type'),
    ];

    // Display fields in a table format.
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('DOI fields are returned by Crossref. Please choose field from your Islandora Installation to hold the returned value.'),
    ];

    $form['field_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('DOI Field'),
        $this->t('Islandora Field'),
      ],
      '#prefix' => '<div id="field-table-wrapper">',
      '#suffix' => '</div>',
    ];
    foreach ($doi_fields as $machine_name => $field) {
      $form['field_table'][$machine_name]['field_name'] = [
        '#plain_text' => $field,
      ];

      // Add a dropdown for each field.
      $form['field_table'][$machine_name]['dropdown'] = [
        '#type' => 'select',
        '#options' => $field_options,
        '#default_value' => $config->get('field_settings')[$machine_name] ?? '',
      ];
    }
    $form['#attached']['library'][] = 'doi_prefill/doi_field_selector_styles';

    $form['pairs_description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('DOI genre terms are returned by Crossref.  Please choose any term you would like to replace from your own taxonomy.'),
    ];

    $doi_term_islandora_term_pairs = $form_state->get('doi_term_islandora_term_pairs');
    if (!$doi_term_islandora_term_pairs) {
      $doi_term_islandora_term_pairs = $config->get('doi_term_islandora_term_pairs');
    }
    if (empty($doi_term_islandora_term_pairs)) {
      // Initialize as an empty array if no pairs exist.
      $doi_term_islandora_term_pairs = [];
    }

    // Set the form state for entry_count and doi_term_islandora_term_pairs.
    $form_state->set('doi_term_islandora_term_pairs', $doi_term_islandora_term_pairs);
    $entry_count = count($doi_term_islandora_term_pairs);
    $form_state->set('entry_count', $entry_count);

    // Define the table structure for key-value pairs.
    $form['doi_term_islandora_term_pairs'] = [
      '#type' => 'table',
      '#prefix' => '<div id="key-value-pairs-wrapper">',
      '#suffix' => '</div>',
    ];

    // Generate the table rows dynamically based on stored pairs.
    foreach ($doi_term_islandora_term_pairs as $entry_id => $pair) {
      $pair['entry_id'] = $entry_id;
      $unique_id = $pair['entry_id'] ?? uniqid();
      $form['doi_term_islandora_term_pairs'][$unique_id]['key'] = [
        '#type' => 'textfield',
        '#default_value' => $pair['key'] ?? '',
        '#title' => $this->t('term from DOI'),
        '#required' => TRUE,
      ];

      $form['doi_term_islandora_term_pairs'][$unique_id]['value'] = [
        '#type' => 'textfield',
        '#default_value' => $pair['value'] ?? '',
        '#title' => $this->t('Genre term'),
        '#required' => TRUE,
      ];

      // Hidden field to store the correct entry ID.
      $form['doi_term_islandora_term_pairs'][$unique_id]['entry_id'] = [
        '#type' => 'hidden',
        '#value' => $entry_id,
      ];

      // Remove button for each entry.
      $form['doi_term_islandora_term_pairs'][$unique_id]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#submit' => ['::removeCallback'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'key-value-pairs-wrapper',
        ],
        // Instead of relying on `#attributes`, set a unique `#name`!
        '#name' => 'remove_' . $unique_id,
      ];

    }

    // Button to add another key-value pair.
    $form['add_more'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add term'),
      '#submit' => ['::addMoreCallback'],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'key-value-pairs-wrapper',
      ],
    ];

    // Submit button for the form.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save term mappings'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!empty($form_state->getValue('field_table'))) {
      foreach ($form_state->getValue('field_table') as $doi_field => $islandora_field) {
        $field_settings[$doi_field] = $islandora_field['dropdown'];
      }
    }
    $doi_term_islandora_term_pairs = $form_state->getValue('doi_term_islandora_term_pairs');
    $content_type = $form_state->getValue('content_type');
    $this->config('doi_prefill.settings')
      ->set('field_settings', $field_settings)
      ->set('doi_term_islandora_term_pairs', $doi_term_islandora_term_pairs)
      ->set('content_type', $content_type)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * AJAX callback to refresh the form.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['doi_term_islandora_term_pairs'];
  }

  /**
   * Adds more key-value pair fields.
   */
  public function addMoreCallback(array &$form, FormStateInterface $form_state) {
    // Retrieve existing key-value pairs.
    $doi_term_islandora_term_pairs = $form_state->get('doi_term_islandora_term_pairs') ?? [];

    // Get submitted values and merge with stored ones.
    $user_input = $form_state->getUserInput();
    if (!empty($user_input['doi_term_islandora_term_pairs'])) {
      foreach ($user_input['doi_term_islandora_term_pairs'] as $id => $values) {
        if (!isset($doi_term_islandora_term_pairs[$id])) {
          $doi_term_islandora_term_pairs[$id] = $values;
        }
      }
    }

    // Add a new empty entry with a unique ID.
    $unique_id = uniqid();
    $doi_term_islandora_term_pairs[$unique_id] = [
      'key' => '',
      'value' => '',
      'unique_id' => $unique_id,
    ];

    // Store the updated values.
    $form_state->set('doi_term_islandora_term_pairs', $doi_term_islandora_term_pairs);
    $form_state->set('entry_count', count($doi_term_islandora_term_pairs));

    // Rebuild the form.
    $form_state->setRebuild();
  }

  /**
   * Remove chosen element.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    // Using hidden field to get around Drupal's issues with ajax.
    $button_name = $triggering_element['#name'] ?? '';

    // Extract the unique ID from the button name.
    if (preg_match('/remove_(.+)/', $button_name, $matches)) {
      $clicked_id = $matches[1];
    }
    $doi_term_islandora_term_pairs = $form_state->get('doi_term_islandora_term_pairs') ?? [];

    if (isset($doi_term_islandora_term_pairs[$clicked_id])) {
      unset($doi_term_islandora_term_pairs[$clicked_id]);
    }
    $form_state->set('doi_term_islandora_term_pairs', $doi_term_islandora_term_pairs);
    $form_state->set('entry_count', count($doi_term_islandora_term_pairs));
    $form_state->setRebuild();
  }

}
