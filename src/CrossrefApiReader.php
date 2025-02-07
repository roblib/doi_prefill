<?php

declare(strict_types=1);

namespace Drupal\doi_prefill;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Simple API class to read Crossref.
 *
 * See https://api.crossref.org/swagger-ui/index.html#/
 */
final class CrossrefApiReader {

  /**
   * Constructs a CrossrefApiReader object.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelInterface $loggerChannelIslandora,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns Crossref data from DOI.
   */
  public function getWork($identifier) {
    $site_email = $this->configFactory->get('system.site')->get('mail');
    $endpoint = 'https://api.crossref.org/works';
    $encoded_doi = urlencode($identifier);
    $url = "{$endpoint}/{$encoded_doi}";
    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'accept' => 'application/json',
          'User-Agent' => 'YourProjectName/1.0 (mailto:your-email@example.com)',
          'User-Agent' => "IslandScholar/ (mailto:{$site_email})",
        ],
      ]);
      $data = $response->getBody()->getContents();
      $values = json_decode($data, TRUE);

      return $values['message'];
    }
    catch (\Exception $e) {
      $this->loggerChannelIslandora->error('Failed to fetch citation from CrossRef: ' . $e->getMessage());
      return NULL;
    }
  }

}
