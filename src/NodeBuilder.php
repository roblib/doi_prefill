<?php

declare(strict_types=1);

namespace Drupal\doi_prefill;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Config\ConfigFactory;

/**
 * Class to construct nodes from crossref DOI harvest.
 */
final class NodeBuilder {

  /**
   * Constructs a CrossrefApiReader object.
   */
  public function __construct(
    private readonly EntityTypeManager $entityTypeManager,
    private readonly CrossrefApiReader $doiApi,
    private readonly ConfigFactory $config
  ) {}

  /**
   * Builds and saves new node.
   *
   * @param int $collection
   *   The node ID of the collection.
   * @param string $doi
   *   The DOI URL associated with the content.
   *
   * @return string
   *   The id of new node.
   */
  public function buildNode($collection, $doi) {
    $contents = $this->doiApi->getWork($doi);
    $config = $this->config->get('doi_prefill.settings');
    $field_settings = $config->get('field_settings');
    $mapping = $config->get('doi_term_islandora_term_pairs');
    $term_mappings = [];
    foreach ($mapping as $mapping => $values) {
      $term_mappings[$values['key']] = $values['value'];
    }

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
    $type = $term_mappings[$contents['type']] ?? $contents['type'];
    $genre = $this->getOrCreateTerm($type, 'genre');

    // Build new node.
    $new_node = Node::create([
      $field_settings['title'] => $contents['title'][0],
      'field_member_of' => $collection,
      'type' => $config->get('content_type'),
      $field_settings['contributors'] => $typed_relations,
      $field_settings['publisher'] => $contents['publisher'] ?? '',
      $field_settings['doi'] => $doi,
      $field_settings['genre'] => $genre->id(),
      $field_settings['issue'] => $contents['issue'] ?? '',
      $field_settings['volume'] => $contents['volume'] ?? '',
      $field_settings['date_issued'] => $contents['created']['date-parts'][0][0] ?? '',
      'status' => 0,
    ]);

    // Optional fields.
    if (isset($contents['abstract'])) {
      $new_node->set($field_settings['abstract'], [
        'value' => $contents['abstract'],
        'format' => 'basic_html',
      ]);
    }
    if (isset($contents['container-title'])) {
      $new_node->set($field_settings['host_title'], $contents['container-title'][0]);
    }
    if (isset($contents['published-online'])) {
      $field_date_online = [];
      foreach ($contents['published-online']['date-parts'] as $date_parts) {
        foreach ($date_parts as &$date_part) {
          $date_part = str_pad((string)$date_part, 2, "0", STR_PAD_LEFT);
        }
        $field_date_online[] = ['value' => implode('-', $date_parts)];
      }
      $new_node->set($field_settings['date_online'], $field_date_online);
    }
    if (isset($contents['page'])) {
      $new_node->set($field_settings['page_range'], $contents['page']);
    }

    // Multivalued fields.
    $field_series_issn = [];
    foreach (($contents['ISSN'] ?? []) as $issn) {
      $field_series_issn[] = ['value' => $issn];
    }
    $new_node->set($field_settings['series_issn'], $field_series_issn);
    $new_node->save();

    return $new_node->id();
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
      return reset($terms);
    }
    $term = Term::create([
      'name' => $term_name,
      'vid' => $vocabulary,
    ]);
    $term->save();
    return $term;
  }

}
