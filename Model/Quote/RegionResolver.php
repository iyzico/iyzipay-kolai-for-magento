<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Quote;

use Kolai\Address\TurkeyProvinces;
use Magento\Directory\Model\RegionFactory;

/**
 * Maps the normalized state ("TR34") onto Magento's region fields.
 *
 * Core Magento ships no regions for Turkey, so for TR this usually ends in the
 * free-text region holding the province name ("İstanbul") — more useful on an
 * invoice than "TR34". A store that imported TR regions gets a real region_id,
 * matched by code ("TR34", "34") or by name.
 */
class RegionResolver
{
    private RegionFactory $regionFactory;

    public function __construct(RegionFactory $regionFactory)
    {
        $this->regionFactory = $regionFactory;
    }

    /**
     * @return array{region_id:?int,region:string}
     */
    public function resolve(string $countryId, string $state): array
    {
        if ($state === '') {
            return ['region_id' => null, 'region' => ''];
        }

        $candidates = [$state];
        $name       = '';
        if ($countryId === 'TR') {
            $plate = TurkeyProvinces::plateCode($state);
            if ($plate !== '') {
                $candidates[] = $plate;
                $candidates[] = 'TR-' . $plate;
            }
            $name = TurkeyProvinces::name($state);
        }

        foreach (array_unique($candidates) as $code) {
            $region = $this->regionFactory->create()->loadByCode($code, $countryId);
            if ($region->getId()) {
                return ['region_id' => (int) $region->getId(), 'region' => (string) $region->getName()];
            }
        }

        if ($name !== '') {
            $region = $this->regionFactory->create()->loadByName($name, $countryId);
            if ($region->getId()) {
                return ['region_id' => (int) $region->getId(), 'region' => (string) $region->getName()];
            }
        }

        return ['region_id' => null, 'region' => $name !== '' ? $name : $state];
    }
}
