<?php

namespace App\Service\Elastica\Client;

use App\Interfaces\SearchInterface;
use App\Service\ElasticaAdapter;
use App\Service\ElasticaPaginator;
use Elastica\Document;
use Elastica\Query;
use Elastica\ResultSet;
use Elastica\Type\Mapping;

/**
 * Class ElasticaClientSearch
 * @package App\Service
 */
class ElasticaClientSearch extends ElasticaClientBase
{
    /**
     * @return string
     */
    protected function getIndex(): string
    {
        return 'search';
    }

    /**
     * Create type & mapping
     */
    public function createMapping(): void
    {
        // Create a type
        $elasticaType = $this->client->getClient()->getIndex($this->getIndex())->getType('_doc');

        // Define mapping
        $mapping = new Mapping();
        $mapping->setType($elasticaType);

        // Set mapping
        $mapping->setProperties([
            'id'       => array('type' => 'text',  'fielddata' => true),
            'iri'      => array('type' => 'text'),
            'entityId' => array('type' => 'integer'),
            'type'     => array('type' => 'text', 'analyzer' => 'index_tokenizer_analyzer', 'search_analyzer' => 'standard'),
            'text'     => array('type' => 'text', 'analyzer' => 'index_tokenizer_analyzer', 'search_analyzer' => 'standard'),
        ]);

        // Send mapping to type
        $mapping->send();
    }

    /**
     * @param SearchInterface $entity
     * @return array
     */
    public function toArray(SearchInterface $entity): array
    {
        $class = self::getEntityClass($entity);

        return [
            'id'       => strtolower($class) . '_' . $entity->getId(),
            'iri'      => $this->iriConverter->getIriFromItem($entity),
            'entityId' => $entity->getId(),
            'type'     => $class,
            'text'     => $entity->getSearchText(),
        ];
    }

    /**
     * @param SearchInterface $entity
     * @return mixed
     */
    protected static function getEntityClass(SearchInterface $entity)
    {
        $path = explode('\\', \get_class($entity));

        return array_pop($path);
    }

    /**
     * @param array $params
     * @return ElasticaPaginator
     */
    public function search(array $params): ElasticaPaginator
    {
        $query = new Query();
        $boolQuery = new Query\BoolQuery();

        if (isset($params['q'])) {
            $term = new Query\Term();
            $term->setTerm('text', $params['q']);
            $boolQuery->addMust($term);
        }

        if (isset($params['type'])) {
            $term = new Query\Term();
            $term->setTerm('type', $params['type']);
            $boolQuery->addMust($term);
        }

        $query->setQuery($boolQuery);

        $page = isset($params['page']) ? $params['page'] : 1;
        $size = isset($params['perPage']) ? $params['perPage'] : 20;
        $from = $size * ($page - 1);

        $query
            ->setFrom($from)
            ->setSize($size)
            ->setSort(['id' => 'desc'])
        ;

        $search = $this->client->createSearch($this->getIndex());
        $search->setQuery($query);

        $results = $search->search();

        $data = array_map(function (Document $document) {
            return $document->toArray()['_source'];
        }, $results->getDocuments());

        $adapter = new ElasticaAdapter($data);
        $adapter->setNbResults($results->getTotalHits());

        # Elastica paginator
        $elasticaPaginator = new ElasticaPaginator($adapter);
        $elasticaPaginator->setCurrentPage($page);
        $elasticaPaginator->setMaxPerPage($size);

        return $elasticaPaginator;
    }
}
