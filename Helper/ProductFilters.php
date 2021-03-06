<?php

namespace Pimgento\Api\Helper;

use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Pimgento\Api\Helper\Data as Helper;
use Pimgento\Api\Helper\Config as ConfigHelper;
use Pimgento\Api\Helper\Store as StoreHelper;
use Pimgento\Api\Helper\Locales as LocalesHelper;
use Pimgento\Api\Model\Source\Filters\Completeness;
use Pimgento\Api\Model\Source\Filters\Mode;
use Pimgento\Api\Model\Source\Filters\Status;

/**
 * Class ProductFiltersHelper
 *
 * @category  Class
 * @package   Pimgento\Api\Helper
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2018 Agence Dn'D
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
class ProductFilters extends Helper
{
    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * This variable contains a StoreHelper
     *
     * @var StoreHelper $storeHelper
     */
    protected $storeHelper;
    /**
     * This variable contains a LocaleHelper
     *
     * @var Pimgento\Api\Helper\Locales $localesHelper
     */
    protected $localesHelper;
    /**
     * This variable contains a SearchBuilder
     *
     * @var SearchBuilder $searchBuilder
     */
    protected $searchBuilder;

    /**
     * ProductFilters constructor
     *
     * @param ConfigHelper  $configHelper
     * @param Store         $storeHelper
     * @param Locales       $localesHelper
     * @param SearchBuilder $searchBuilder
     * @param Context       $context
     */
    public function __construct(
        ConfigHelper $configHelper,
        StoreHelper $storeHelper,
        LocalesHelper $localesHelper,
        SearchBuilder $searchBuilder,
        Context $context
    ) {
        parent::__construct($context);

        $this->configHelper  = $configHelper;
        $this->storeHelper   = $storeHelper;
        $this->localesHelper = $localesHelper;
        $this->searchBuilder = $searchBuilder;
    }

    /**
     * Get the filters for the product API query
     *
     * @return mixed[]|string[]
     */
    public function getFilters()
    {
        /** @var mixed[] $mappedChannels */
        $mappedChannels = $this->configHelper->getMappedChannels();
        if (empty($mappedChannels)) {
            /** @var string[] $error */
            $error = [
                'error' => __('No website/channel mapped. Please check your configurations.'),
            ];

            return $error;
        }

        /** @var mixed[] $filters */
        $filters = [];
        /** @var mixed[] $search */
        $search = [];

        /** @var string $mode */
        $mode = $this->configHelper->getFilterMode();
        if ($mode == Mode::ADVANCED) {
            /** @var mixed[] $advancedFilters */
            $advancedFilters = $this->getAdvancedFilters();
            if (!empty($advancedFilters['scope'])) {
                if (!in_array($advancedFilters['scope'], $mappedChannels)) {
                    /** @var string[] $error */
                    $error = [
                        'error' => __('Advanced filters contains an unauthorized scope, please add check your filters and website mapping.'),
                    ];

                    return $error;
                }

                return [$advancedFilters];
            }

            $search = $advancedFilters['search'];
        }

        if ($mode == Mode::STANDARD) {
            $this->addCompletenessFilter();
            $this->addStatusFilter();
            $this->addFamiliesFilter();
            $this->addUpdatedFilter();
            $search = $this->searchBuilder->getFilters();
        }

        /** @var string $channel */
        foreach ($mappedChannels as $channel) {
            /** @var string[] $filter */
            $filter = [
                'search' => $search,
                'scope'  => $channel,
            ];

            if ($mode == Mode::ADVANCED) {
                $filters[] = $filter;

                continue;
            }

            if ($this->configHelper->getCompletenessTypeFilter() !== Completeness::NO_CONDITION) {
                /** @var string[] $completeness */
                $completeness = reset($search['completeness']);
                if (!empty($completeness['scope']) && $completeness['scope'] !== $channel) {
                    $completeness['scope']  = $channel;
                    $search['completeness'] = [$completeness];

                    $filter['search'] = $search;
                }
            }

            /** @var string[] $locales */
            $locales = $this->storeHelper->getChannelStoreLangs($channel);
            if (!empty($locales)) {
                /** @var string $locales */
                $akeneoLocales = $this->localesHelper->getAkeneoLocales();
                if (!empty($akeneoLocales)) {
                    $locales = array_intersect($locales, $akeneoLocales);
                }

                /** @var string $locales */
                $locales           = implode(',', $locales);
                $filter['locales'] = $locales;
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * Retrieve advanced filters config
     *
     * @return mixed[]
     */
    protected function getAdvancedFilters()
    {
        /** @var mixed[] $filters */
        $filters = $this->configHelper->getAdvancedFilters();

        return $filters;
    }

    /**
     * Add completeness filter for Akeneo API
     *
     * @return void
     */
    protected function addCompletenessFilter()
    {
        /** @var string $filterType */
        $filterType = $this->configHelper->getCompletenessTypeFilter();
        if ($filterType === Completeness::NO_CONDITION) {
            return;
        }

        /** @var string $scope */
        $scope = $this->configHelper->getAdminDefaultChannel();
        /** @var mixed[] $options */
        $options = ['scope' => $scope];

        /** @var string $filterValue */
        $filterValue = $this->configHelper->getCompletenessValueFilter();

        /** @var string[] $localesType */
        $localesType = [
            Completeness::LOWER_OR_EQUALS_THAN_ON_ALL_LOCALES,
            Completeness::LOWER_THAN_ON_ALL_LOCALES,
            Completeness::GREATER_THAN_ON_ALL_LOCALES,
            Completeness::GREATER_OR_EQUALS_THAN_ON_ALL_LOCALES,
        ];
        if (in_array($filterType, $localesType)) {
            /** @var mixed $locales */
            $locales = $this->configHelper->getCompletenessLocalesFilter();
            /** @var string[] $locales */
            $locales            = explode(',', $locales);
            $options['locales'] = $locales;
        }

        $this->searchBuilder->addFilter('completeness', $filterType, $filterValue, $options);

        return;
    }

    /**
     * Add status filter for Akeneo API
     *
     * @return void
     */
    protected function addStatusFilter()
    {
        /** @var string $filter */
        $filter = $this->configHelper->getStatusFilter();
        if ($filter === Status::STATUS_NO_CONDITION) {
            return;
        }
        $this->searchBuilder->addFilter('enabled', '=', (bool)$filter);

        return;
    }

    /**
     * Add updated filter for Akeneo API
     *
     * @return void
     */
    protected function addUpdatedFilter()
    {
        /** @var string $filter */
        $filter = $this->configHelper->getUpdatedFilter();
        if (!is_numeric($filter)) {
            return;
        }
        $this->searchBuilder->addFilter('updated', 'SINCE LAST N DAYS', (int)$filter);

        return;
    }

    /**
     * Add families filter for Akeneo API
     *
     * @return void
     */
    protected function addFamiliesFilter()
    {
        /** @var mixed $filter */
        $filter = $this->configHelper->getFamiliesFilter();
        if (!$filter) {
            return;
        }

        /** @var string[] $filter */
        $filter = explode(',', $filter);

        $this->searchBuilder->addFilter('family', 'NOT IN', $filter);

        return;
    }
}
