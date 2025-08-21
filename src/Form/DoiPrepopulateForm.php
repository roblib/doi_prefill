<?php

declare(strict_types=1);

namespace Drupal\doi_prefill\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\doi_prefill\CrossrefApiReader;
use Drupal\doi_prefill\NodeBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Render\Markup;
use Drupal\Core\Messenger\MessengerInterface;
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
   * The Node builder.
   *
   * @var Drupal\doi_prefill\NodeBuilder
   */
  protected $nodeBuilder;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The constructor.
   *
   * @param \Drupal\doi_prefill\CrossrefApiReader $doiApi
   *   The Api reader.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The EntityTypeManager.
   * @param Drupal\doi_prefill\NodeBuilder $nodeBuilder
   *   The NodeBuilder.
   */
  public function __construct(CrossrefApiReader $doiApi, EntityTypeManagerInterface $entityTypeManager, NodeBuilder $nodeBuilder, MessengerInterface $messenger) {
    $this->doiApi = $doiApi;
    $this->entityTypeManager = $entityTypeManager;
    $this->nodeBuilder = $nodeBuilder;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      // Load the service required to construct this class.
      $container->get('doi_prefill.crossref_api_reader'),
      $container->get('entity_type.manager'),
      $container->get('doi_prefill.node_builder'),
      $container->get('messenger'),
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $doi = trim($form_state->getValue('doi'));
    if (!empty($doi)) {
      $existing_nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
        'field_doi' => $doi,
      ]);

      if (!empty($existing_nodes)) {
        $tags = [];
        foreach ($existing_nodes as $node) {
          $tags[] = "<a href='{$node->toUrl()->toString()}'>{$doi}</a>";
        }
        $message = $this->t("DOI already exists in the system.");
        $links = implode("<br />", $tags);
        $message = "{$message}<br />{$links}";
        $form_state->setErrorByName('doi', Markup::create($message));
      }
    }
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
    $form['overview'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Enter <strong>DOI</strong> to prepopulate a new Drupal node with values from Crossref.<br /><strong>Note:</strong> The new node will be in an unpublished state.'),
      '#allowed_tags' => ['br', 'strong'],
    ];
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
      '#type' => 'hidden',
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
    $nid = $this->nodeBuilder->buildNode($collection, $doi);
    if (empty($nid)) {
      $this->messenger->addWarning($this->t('Crossref returned no information.'));
    }
    else {
      $destination = "/node/{$nid}/edit";
      $response = new RedirectResponse($destination);
      $response->send();
    }
  }

}
