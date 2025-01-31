<?php

declare(strict_types=1);

namespace Drupal\doi_prefill\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\doi_prefill\CrossrefApiReader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a DOI Prefill form.
 */
final class DoiPrepopulateForm extends FormBase {

  /**
   * The DOI API reader.
   *
   * @var \Drupal\doi_prefill\CrossrefApiReader
   */
  protected $doiApi;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The constructor.
   *
   * @param \Drupal\doi_prefill\CrossrefApiReader $doiApi
   */
  public function __construct(CrossrefApiReader $doiApi, EntityTypeManagerInterface $entityTypeManager) {
    $this->doiApi = $doiApi;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      // Load the service required to construct this class.
      $container->get('doi_prefill.crossref_api_reader'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'doi_prefill_doi_prepopulate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'name' => 'Collection',
      'vid' => 'islandora_models',
    ]);
    $term = reset($terms);
    $collection_ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('field_model', $term->id())
      ->execute();
    $collections = $this->entityTypeManager->getStorage('node')->loadMultiple($collection_ids);
    $options = [];
    foreach ($collections as $id => $collection) {
      $options[$id] = $collection->label();
    }

    $form['container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-inline']],
    ];
    $form['container']['doi'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter DOI'),
      '#required' => TRUE,
    ];
    $form['container']['collection'] = [
      '#title' => $this->t('Collection'),
      '#type' => 'select',
      '#options' => $options,
      '#required' => TRUE,

    ];
    $form['container']['redirect'] = [
      '#type' => 'select',
      '#title' => $this->t("After submission?"),
      '#options' => [
        'edit' => $this->t('Edit after submission'),
        'resume' => $this->t('Return to this form'),
      ],
      '#default_value' => 'edit',
    ];
    $form['#attached']['library'][] = 'doi_prefill/styles';

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $doi = trim($form_state->getValue('doi'));
    $collection = $form_state->getValue('collection');
    $contents = $this->doiApi->getWork($doi);

    // Build typed relations.
    $typed_relations = [];
    $vid = 'person';
    foreach ($contents['author'] as $author) {
      $author_term = "{$author['family']}";
      if (isset($author['given'])) {
        $author_term = "{$author_term}, {$author['given']}";
      }
      $term = $this->getOrCreateTerm($author_term, $vid);
      $typed_relations[] = [
        'target_id' => $term->id(),
        'rel_type' => 'relators:aut',
      ];
    }
    $genre = $this->getOrCreateTerm($contents['type'], 'genre');

    // Build new node.
    $new_node = Node::create([
      'title' => $contents['title'][0],
      'field_member_of' => $collection,
      'type' => 'islandora_object',
      'field_linked_agent' => $typed_relations,
      'field_publisher' => $contents['publisher'],
      'field_doi' => $doi,
      'field_genre' => $genre->id(),
      'field_issue' => $contents['issue'],
      'field_volume' => $contents['volume'],
    ]);

    // Optional fields.
    if (isset($contents['abstract'])) {
      $new_node->set('field_abstract', [
        'value' => $contents['abstract'],
        'format' => 'basic_html',
      ]);
    }
    if (isset($contents['published-online'])) {
      $field_date_online = [];
      foreach ($contents['published-online']['date-parts'] as $date_parts) {
        $field_date_online[] = ['value' => implode('-', $date_parts)];
      }
      $new_node->set('field_date_online', $field_date_online);
    }

    // Multivalued fields.
    $field_edtf_date_issued = [];
    foreach ($contents['created']['date-parts'] as $date_parts) {
      $field_edtf_date_issued[] = ['value' => implode('-', $date_parts)];
    }
    $new_node->set('field_edtf_date_issued', $field_edtf_date_issued);

    $field_issn = [];
    foreach ($contents['ISSN'] as $issn) {
      $field_issn[] = ['value' => $issn];
    }
    $new_node->set('field_issn', $field_issn);
    $new_node->save();
    if ($form_state->getValue('redirect') == 'edit') {

      $destination = "/node/{$new_node->id()}/edit";
      $response = new RedirectResponse($destination);
      $response->send();
    }

  }

  /**
   * Check if a term exists in a vocabulary. If not, create it.
   *
   * @param string $term_name
   *   The name of the term.
   * @param string $vocabulary
   *   The machine name of the vocabulary.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The term entity if found or created, or NULL on failure.
   */
  public function getOrCreateTerm($term_name, $vocabulary) {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'name' => $term_name,
      'vid' => $vocabulary,
    ]);

    if ($terms) {
      // Return the first found term.
      return reset($terms);
    }

    // If the term does not exist, create it.
    $term = Term::create([
      'name' => $term_name,
      'vid' => $vocabulary,
    ]);
    $term->save();
    return $term;

  }

}
