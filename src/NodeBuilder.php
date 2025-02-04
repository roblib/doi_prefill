<?php

declare(strict_types=1);

namespace Drupal\doi_prefill;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Class to construct nodes from crossref DOI harvest.
 */
final class NodeBuilder {

  /**
   * Mapping of content types to their respective classes.
   *
   * @var arraystringstring
   */
  protected $mapping = [
    'journal-article' => 'Journal Article',
    'book-chapter' => 'Book, Section',
    'monograph' => 'Book',
    'proceedings-article' => 'Conference Proceedings',
  ];

  /**
   * Constructs a CrossrefApiReader object.
   */
  public function __construct(
    private readonly EntityTypeManager $entityTypeManager,
    private readonly CrossrefApiReader $doiApi,
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
    $type = $this->mapping[$contents['type']] ?? $contents['type'];
    $genre = $this->getOrCreateTerm($type, 'genre');

    // Build new node.
    $new_node = Node::create([
      'title' => $contents['title'][0],
      'field_member_of' => $collection,
      'type' => 'islandora_object',
      'field_contributors' => $typed_relations,
      'field_publisher' => $contents['publisher'] ?? '',
      'field_doi' => $doi,
      'field_genre' => $genre->id(),
      'field_issue' => $contents['issue'] ?? '',
      'field_volume' => $contents['volume'] ?? '',
      'field_date_issued' => $contents['created']['date-parts'][0][0] ?? '',
    ]);

    // Optional fields.
    if (isset($contents['abstract'])) {
      $new_node->set('field_abstract', [
        'value' => $contents['abstract'],
        'format' => 'basic_html',
      ]);
    }
    if (isset($contents['container-title'])) {
      $new_node->set('field_host_title', $contents['container-title'][0]);
    }
    if (isset($contents['published-online'])) {
      $field_date_online = [];
      foreach ($contents['published-online']['date-parts'] as $date_parts) {
        $field_date_online[] = ['value' => implode('-', $date_parts)];
      }
      $new_node->set('field_date_online', $field_date_online);
    }
    if (isset($contents['page'])) {
      $new_node->set('field_page_range', $contents['page']);
    }

    // Multivalued fields.
    $field_series_issn = [];
    foreach (($contents['ISSN'] ?? []) as $issn) {
      $field_series_issn[] = ['value' => $issn];
    }
    $new_node->set('field_series_issn', $field_series_issn);
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
