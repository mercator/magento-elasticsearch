<?php
/**
 * Elasticsearch engine.
 *
 * @category Bubble
 * @package Bubble_Search
 * @author Johann Reinke <johann@bubblecode.net>
 */
class Bubble_Search_Model_Resource_Engine_Elasticsearch extends Bubble_Search_Model_Resource_Engine_Abstract
{
    const CACHE_INDEX_PROPERTIES_ID = 'elasticsearch_index_properties';

    /**
     * Initializes search engine.
     *
     * @see Bubble_Search_Model_Resource_Engine_Elasticsearch_Client
     */
    public function __construct()
    {
        $this->_client = Mage::getResourceSingleton('bubble_search/engine_elasticsearch_client');
    }

    /**
     * Cleans caches.
     *
     * @return Bubble_Search_Model_Resource_Engine_Elasticsearch
     */
    public function cleanCache()
    {
        Mage::app()->removeCache(self::CACHE_INDEX_PROPERTIES_ID);

        return $this;
    }

    /**
     * Cleans index.
     *
     * @param int $storeId
     * @param int $id
     * @param string $type
     * @return Bubble_Search_Model_Resource_Engine_Elasticsearch
     */
    public function cleanIndex($storeId = null, $id = null, $type = 'product')
    {
        $this->_client->cleanIndex($storeId, $id, $type);

        return $this;
    }

    /**
     * Deletes index.
     *
     * @return mixed
     */
    public function deleteIndex()
    {
        return $this->_client->deleteIndex();
    }

    /**
     * Retrieves stats for specified query.
     *
     * @param string $query
     * @param array $params
     * @param string $type
     * @return array
     */
    public function getStats($query, $params = array(), $type = 'product')
    {
        $stats = $this->_search($query, $params, $type);

        return isset($stats['facets']['stats']) ? $stats['facets']['stats'] : array();
    }

    /**
     * Saves products data in index.
     *
     * @param int $storeId
     * @param array $indexes
     * @param string $type
     * @return Bubble_Search_Model_Resource_Engine_Elasticsearch
     */
    public function saveEntityIndexes($storeId, $indexes, $type = 'product')
    {
        $indexes = $this->addAdvancedIndex($indexes, $storeId, array_keys($indexes));

        $helper = $this->_getHelper();
        $store = Mage::app()->getStore($storeId);
        $localeCode = $helper->getLocaleCode($store);
        $searchables = $helper->getSearchableAttributes();
        $sortables = $helper->getSortableAttributes();

        foreach ($indexes as &$data) {
            foreach ($data as $key => &$value) {
                if (is_array($value)) {
                    $value = array_values(array_filter(array_unique($value)));
                }
                if (array_key_exists($key, $searchables)) {
                    /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
                    $attribute = $searchables[$key];
                    if ($attribute->getBackendType() == 'datetime') {
                        foreach ($value as &$date) {
                            $date = $this->_getDate($store->getId(), $date);
                        }
                        unset($date);
                    } elseif ($attribute->usesSource() && !empty($value)) {
                        if ($attribute->getFrontendInput() == 'multiselect') {
                            $value = explode(',', is_array($value) ? $value[0] : $value);
                        } elseif ($helper->isAttributeUsingOptions($attribute)) {
                            $val = is_array($value) ? $value[0] : $value;
                            if (!isset($data['_options'])) {
                                $data['_options'] = array();
                            }
                            $option = $attribute->setStoreId($storeId)
                                ->getFrontend()
                                ->getOption($val);
                            $data['_options'][] = $option;
                        }
                    }
                }
                if (array_key_exists($key, $sortables)) {
                    $val = is_array($value) ? $value[0] : $value;
                    /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
                    $attribute = $sortables[$key];
                    $attribute->setStoreId($store->getId());
                    $key = $helper->getSortableAttributeFieldName($sortables[$key], $localeCode);
                    if ($attribute->usesSource()) {
                        $val = $attribute->getFrontend()->getOption($val);
                    } elseif ($attribute->getBackendType() == 'decimal') {
                        $val = (double) $val;
                    }
                    $data[$key] = $val;
                }
            }
            unset($value);
            $data['store_id'] = $store->getId();
        }
        unset($data);

        $docs = $this->_prepareDocs($indexes, $type, $localeCode);
        $this->_addDocs($docs);

        return $this;
    }

    /**
     * Checks Elasticsearch availability.
     *
     * @return bool
     */
    public function test()
    {
        if (null !== $this->_test) {
            return $this->_test;
        }

        try {
            $this->_client->getStatus();
            $this->_test = true;
        } catch (Exception $e) {
            if ($this->_getHelper()->isDebugEnabled()) {
                $this->_getHelper()->showError('Elasticsearch engine is not available');
            }
            $this->_test = false;
        }

        return $this->_test;
    }

    /**
     * Adds documents to index.
     *
     * @param array $docs
     * @return Bubble_Search_Model_Resource_Engine_Elasticsearch
     */
    protected function _addDocs($docs)
    {
        if (!empty($docs)) {
            $this->_client->addDocuments($docs);
        }
        $this->_client->refreshIndex();

        return $this;
    }

    /**
     * Creates and prepares document for indexation.
     *
     * @param int $entityId
     * @param array $index
     * @param string $type
     * @return mixed
     */
    protected function _createDoc($entityId, $index, $type = 'product')
    {
        return $this->_client->createDoc($index[self::UNIQUE_KEY], $index, $type);
    }

    /**
     * Escapes specified value.
     *
     * @link http://lucene.apache.org/core/3_6_0/queryparsersyntax.html
     * @param string $value
     * @return mixed
     */
    protected function _escape($value)
    {
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Escapes specified phrase.
     *
     * @param string $value
     * @return string
     */
    protected function _escapePhrase($value)
    {
        $pattern = '/("|\\\)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Returns search helper.
     *
     * @return Bubble_Search_Helper_Elasticsearch
     */
    protected function _getHelper()
    {
        return Mage::helper('bubble_search/elasticsearch');
    }

    /**
     * Phrases specified value.
     *
     * @param string $value
     * @return string
     */
    protected function _phrase($value)
    {
        return '"' . $this->_escapePhrase($value) . '"';
    }

    /**
     * Prepares facets conditions.
     *
     * @param array $facetsFields
     * @return array
     */
    protected function _prepareFacetsConditions($facetsFields)
    {
        $result = array();
        if (is_array($facetsFields)) {
            foreach ($facetsFields as $facetField => $facetFieldConditions) {
                if (empty($facetFieldConditions)) {
                    $result['fields'][] = $facetField;
                } else {
                    foreach ($facetFieldConditions as $facetCondition) {
                        if (is_array($facetCondition) && isset($facetCondition['from']) && isset($facetCondition['to'])) {
                            $from = (isset($facetCondition['from']) && strlen(trim($facetCondition['from'])))
                                ? $this->_prepareQueryText($facetCondition['from'])
                                : '';
                            $to = (isset($facetCondition['to']) && strlen(trim($facetCondition['to'])))
                                ? $this->_prepareQueryText($facetCondition['to'])
                                : '';
                            if (!$from) {
                                unset($facetCondition['from']);
                            } else {
                                $facetCondition['from'] = $from;
                            }
                            if (!$to) {
                                unset($facetCondition['to']);
                            } else {
                                $facetCondition['to'] = $to;
                            }
                            $result['ranges'][$facetField][] = $facetCondition;
                        } else {
                            $facetCondition = $this->_prepareQueryText($facetCondition);
                            $fieldCondition = $this->_prepareFieldCondition($facetField, $facetCondition);
                            $result['queries'][] = $fieldCondition;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Prepares facets query response.
     *
     * @param mixed $response
     * @return array
     */
    protected function _prepareFacetsQueryResponse($response)
    {
        $result = array();
        foreach ($response as $attr => $data) {
            if (isset($data['terms'])) {
                foreach ($data['terms'] as $value) {
                    $result[$attr][$value['term']] = $value['count'];
                }
            } elseif (isset($data['_type']) && $data['_type'] == 'statistical') {
                $result['stats'][$attr] = $data;
            } elseif (isset($data['ranges'])) {
                foreach ($data['ranges'] as $range) {
                    $from = isset($range['from_str']) ? $range['from_str'] : '';
                    $to = isset($range['to_str']) ? $range['to_str'] : '';
                    $result[$attr]["[$from TO $to]"] = $range['total_count'];
                }
            } elseif (preg_match('/\(categories:(\d+) OR show_in_categories\:\d+\)/', $attr, $matches)) {
                $result['categories'][$matches[1]] = $data['count'];
            }
        }

        return $result;
    }

    /**
     * Prepares field condition.
     *
     * @param string $field
     * @param string $value
     * @return string
     */
    protected function _prepareFieldCondition($field, $value)
    {
        if ($field == 'categories') {
            $fieldCondition = "(categories:{$value} OR show_in_categories:{$value})";
        } else {
            $fieldCondition = $field . ':' . $value;
        }

        return $fieldCondition;
    }

    /**
     * Prepares filter query text.
     *
     * @param string $text
     * @return mixed|string
     */
    protected function _prepareFilterQueryText($text)
    {
        $words = explode(' ', $text);
        if (count($words) > 1) {
            $text = $this->_phrase($text);
        } else {
            $text = $this->_escape($text);
        }

        return $text;
    }

    /**
     * Prepares filters.
     *
     * @param array $filters
     * @return array
     */
    protected function _prepareFilters($filters)
    {
        $result = array();
        if (is_array($filters) && !empty($filters)) {
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    if ($field == 'price' || isset($value['from']) || isset($value['to'])) {
                        $from = (isset($value['from']))
                            ? $this->_prepareFilterQueryText($value['from'])
                            : '';
                        $to = (isset($value['to']))
                            ? $this->_prepareFilterQueryText($value['to'])
                            : '';
                        $fieldCondition = "$field:[$from TO $to]";
                    } else {
                        $fieldCondition = array();
                        foreach ($value as $part) {
                            $part = $this->_prepareFilterQueryText($part);
                            $fieldCondition[] = $this->_prepareFieldCondition($field, $part);
                        }
                        $fieldCondition = '(' . implode(' OR ', $fieldCondition) . ')';
                    }
                } else {
                    $value = $this->_prepareFilterQueryText($value);
                    $fieldCondition = $this->_prepareFieldCondition($field, $value);
                }

                $result[] = $fieldCondition;
            }
        }

        return $result;
    }

    /**
     * Prepares query response.
     *
     * @param Elastica_ResultSet $response
     * @return array
     */
    protected function _prepareQueryResponse($response)
    {
        /* @var $response Elastica_ResultSet */
        if (!$response instanceof Elastica_ResultSet || $response->getResponse()->hasError() || !$response->count()) {
            return array();
        }
        $this->_lastNumFound = (int) $response->getTotalHits();
        $result = array();
        foreach ($response->getResults() as $doc) {
            $result[] = $this->_objectToArray($doc->getSource());
        }

        return $result;
    }

    /**
     * Prepares query text.
     *
     * @param $text
     * @return string
     */
    protected function _prepareQueryText($text)
    {
        $words = explode(' ', $text);
        if (count($words) > 1) {
            foreach ($words as $key => &$val) {
                if (!empty($val)) {
                    $val = $this->_escape($val);
                } else {
                    unset($words[$key]);
                }
            }
            $text = '(' . implode(' ', $words) . ')';
        } else {
            $text = $this->_escape($text);
        }

        return $text;
    }

    /**
     * Prepares search conditions.
     *
     * @param mixed $query
     * @return string
     */
    protected function _prepareSearchConditions($query)
    {
        if (!is_array($query)) {
            $searchConditions = $this->_prepareQueryText($query);
        } else {
            $searchConditions = array();
            foreach ($query as $field => $value) {
                if (is_array($value)) {
                    if ($field == 'price' || isset($value['from']) || isset($value['to'])) {
                        $from = (isset($value['from']) && strlen(trim($value['from'])))
                            ? $this->_prepareQueryText($value['from'])
                            : '';
                        $to = (isset($value['to']) && strlen(trim($value['to'])))
                            ? $this->_prepareQueryText($value['to'])
                            : '';
                        $fieldCondition = "$field:[$from TO $to]";
                    } else {
                        $fieldCondition = array();
                        foreach ($value as $part) {
                            $part = $this->_prepareFilterQueryText($part);
                            $fieldCondition[] = $field .':'. $part;
                        }
                        $fieldCondition = '('. implode(' OR ', $fieldCondition) .')';
                    }
                } else {
                    if ($value != '*') {
                        $value = $this->_prepareQueryText($value);
                    }
                    $fieldCondition = $field .':'. $value;
                }
                $searchConditions[] = $fieldCondition;
            }
            $searchConditions = implode(' AND ', $searchConditions);
        }

        return $searchConditions;
    }

    /**
     * Prepares sort fields.
     *
     * @param array $sortBy
     * @return array
     */
    protected function _prepareSortFields($sortBy)
    {
        $result = array();
        foreach ($sortBy as $sort) {
            $_sort = each($sort);
            $sortField = $_sort['key'];
            $sortType = $_sort['value'];

            // MERCATOR - Make sure we don't try to sort on category position
            // without a registered current category. Default to relevance in
            // this situation.
            if ($sortField == 'position' && !is_object(Mage::registry('current_category'))) {
                $sortField = 'relevance';
            }

            if ($sortField == 'relevance') {
                $sortField = '_score';
                // MERCATOR - We never want to sort on ascending relevance
                // (i.e. showing the least relevant results first).
                $sortType = 'desc';
            } elseif ($sortField == 'position') {
                $sortField = 'position_category_' . Mage::registry('current_category')->getId();
            } elseif ($sortField == 'price') {
                $websiteId = Mage::app()->getStore()->getWebsiteId();
                $customerGroupId = Mage::getSingleton('customer/session')->getCustomerGroupId();
                $sortField = 'price_'. $customerGroupId .'_'. $websiteId;
            } else {
                $sortField = $this->_getHelper()->getSortableAttributeFieldName($sortField);
            }
            $result[] = array($sortField => trim(strtolower($sortType)));
        }

        return $result;
    }

    /**
     * Performs search and facetting.
     *
     * @param string $query
     * @param array $params
     * @param string $type
     * @return array
     */
    protected function _search($query, $params = array(), $type = 'product')
    {
        $searchConditions = $this->_prepareSearchConditions($query);

        $_params = $this->_defaultQueryParams;
        if (is_array($params) && !empty($params)) {
            $_params = array_intersect_key($params, $_params) + array_diff_key($_params, $params);
        }

        $searchParams = array();
        $searchParams['offset'] = isset($_params['offset'])
            ? (int) $_params['offset']
            : 0;
        $searchParams['limit'] = isset($_params['limit'])
            ? (int) $_params['limit']
            : self::DEFAULT_ROWS_LIMIT;

        if (!is_array($_params['params'])) {
            $_params['params'] = array($_params['params']);
        }

        $searchParams['sort'] = $this->_prepareSortFields($_params['sort_by']);

        $useFacetSearch = (isset($params['facets']) && !empty($params['facets']));
        if ($useFacetSearch) {
            $searchParams['facets'] = $this->_prepareFacetsConditions($params['facets']);
        }

        if (!empty($_params['params'])) {
            foreach ($_params['params'] as $name => $value) {
                $searchParams[$name] = $value;
            }
        }

        if ($_params['store_id'] > 0) {
            $_params['filters']['store_id'] = $_params['store_id'];
        }

        if (!Mage::helper('cataloginventory')->isShowOutOfStock()) {
            $_params['filters']['in_stock'] = '1';
        }

        if (!empty($query)) {
            $visibility = Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds();
        } else {
            $visibility = Mage::getSingleton('catalog/product_visibility')->getVisibleInCatalogIds();
        }
        $_params['filters']['visibility'] = $visibility;

        $searchParams['filters'] = implode(' AND ', $this->_prepareFilters($_params['filters']));

        if (!empty($params['range_filters'])) {
            $searchParams['range_filters'] = $params['range_filters'];
        }

        if (!empty($params['stats'])) {
            $searchParams['stats'] = $params['stats'];
            $useFacetSearch = true;
        }

        $data = $this->_client->search($searchConditions, $searchParams, $type);

        if (!$data instanceof Elastica_ResultSet) {
            return array();
        }

        /* @var $data Elastica_ResultSet */
        if (!isset($params['params']['stats']) || $params['params']['stats'] != 'true') {
            $result = array(
                'ids' => $this->_prepareQueryResponse($data),
                'total_count' => $data->getTotalHits()
            );
            if ($useFacetSearch) {
                $result['facets'] = $this->_prepareFacetsQueryResponse($data->getFacets());
            }
        }

        return $result;
    }
}
