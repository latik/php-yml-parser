<?php

namespace Sirian\YMLParser\Parser;

use Sirian\YMLParser\Exception\UnsupportedOfferTypeException;
use Sirian\YMLParser\Factory\Factory;
use Sirian\YMLParser\Offer\Offer;
use Sirian\YMLParser\Offer\VendorModelOffer;
use Sirian\YMLParser\Shop;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Parser extends EventDispatcher
{
    protected $xmlReader;
    protected $factory;

    private $path = [];

    public function __construct(Factory $factory = null)
    {
        if (null == $factory) {
            $factory = new Factory();
        }

        $this->xmlReader = new \XMLReader();
        $this->factory = $factory;
    }

    public function parse($file)
    {
        $this->path = [];

        $this->xmlReader->open($file);
        $this->read();
        $this->xmlReader->close();
    }

    protected function read()
    {
        $shop = null;
        $xml = $this->xmlReader;
        while ($xml->read()) {
            if ($xml->nodeType == \XMLReader::END_ELEMENT) {
                array_pop($this->path);
                continue;
            }


            if ($xml->nodeType == \XMLReader::ELEMENT) {
                array_push($this->path, $xml->name);
                $path = implode('/', $this->path);

                if ($xml->isEmptyElement) {
                    array_pop($this->path);
                }


                switch ($path) {
                    case 'yml_catalog/shop':
                        $shop = $this->factory->createShop();
                        $this->dispatch('shop', new ShopEvent($shop));
                        break;
                    case 'yml_catalog/shop/currencies':
                        $currencies = $this->parseCurrencies($shop);
                        $this->dispatch('currencies', new CurrenciesEvent($currencies));
                        break;
                    case 'yml_catalog/shop/categories':
                        $categories = $this->parseCategories($shop);
                        $this->dispatch('categories', new CategoriesEvent($categories));
                        break;
                    case 'yml_catalog/shop/offers/offer':
                        try {
                            $offer = $this->parseOffer($shop);
                        } catch (UnsupportedOfferTypeException $e) {
                            break;
                        }

                        $this->dispatch('offer', new OfferEvent($offer));
                        break;
                    default:
                }
            }
        }
    }

    protected function parseCurrencies(Shop $shop)
    {
        $xml = $this->loadElementXml();
        foreach ($xml->currency as $elem) {
            $shop->addCurrency($this->createCurrency($elem, $shop));
        }
        return $shop->getCurrencies();
    }

    protected function parseCategories(Shop $shop)
    {
        $xml = $this->loadElementXml();

        $parents = [];
        foreach ($xml->category as $elem) {
            $shop->addCategory($this->createCategory($elem, $shop));

            foreach (['parentId', 'parent_id'] as $field) {
                if (isset($elem[$field])) {
                    $parents[(string)$elem['id']] = (string)$elem[$field];
                    break;
                }
            }
        }

        foreach ($parents as $id => $parentId) {
            if ($id != $parentId) {
                $parent = $shop->getCategory($parentId);
            } else {
                $parent = null;
            }
            $shop
                ->getCategory($id)
                ->setParent($parent);
        }
        return $shop->getCategories();
    }

    protected function parseOffer(Shop $shop)
    {
        $xml = $this->loadElementXml();

        $offer = $this->createOffer($xml, $shop);

        return $offer;
    }

    protected function createCurrency(\SimpleXMLElement $elem, Shop $shop)
    {
        $id = $this->fixCurrency((string)$elem['id']);

        $currency = $this->factory->createCurrency();
        $currency
            ->setId($id)
            ->setRate((string)$elem['rate'])
            ->setPlus((int)$elem['plus']);

        return $currency;
    }

    protected function createCategory(\SimpleXMLElement $elem, Shop $shop)
    {
        $id = (string)$elem['id'];

        $parents[$id] = (string)$elem['parentId'];

        $category = $this->factory->createCategory();

        $category
            ->setId($id)
            ->setName((string)$elem);

        return $category;
    }

    protected function createParam(\SimpleXMLElement $elem)
    {
        $param = $this->factory->createParam();

        $param
            ->setName((string)$elem['name'])
            ->setUnit((string)$elem['unit'])
            ->setValue((string)$elem);

        return $param;
    }

    protected function createOffer(\SimpleXMLElement $elem, Shop $shop)
    {
        $type = (string)$elem['type'];

        if (!$type) {
            $type = 'vendor.model';
        }

        $offer = $this->factory->createOffer($type);
        foreach ($elem->attributes() as $key => $value) {
            $offer->setAttribute($key, (string)$value);
        }

        $offer
            ->setId((string)$elem['id'])
            ->setAvailable(((string)$elem['available']) == 'true' ? true : false)
            ->setType($type)
            ->setXml($elem);

        /** @var \SimpleXMLElement $value */
        foreach ($elem as $field => $value) {
            $processed = false;
            foreach (['add', 'set'] as $method) {
                $method .= $this->camelize($field);
                if (method_exists($this, $method)) {
                    $this->$method($value, $offer, $shop);
                    $processed = true;
                    break;
                }
            }
            if (!$processed) {
                foreach (['add', 'set'] as $method) {
                    $method .= $this->camelize($field);
                    if (method_exists($offer, $method)) {
                        $offer->$method($value->children() ? $value : (string)$value);
                        $processed = true;
                        break;
                    }
                }
            }
            if (!$processed) {
                $value->addAttribute('name', $field);
                $this->addParam($value, $offer);
            }
        }

        return $offer;
    }

    protected function addParam(\SimpleXMLElement $elem, VendorModelOffer $offer)
    {
        $offer->addParam(
            $this->createParam($elem)
        );
    }

    protected function addAddParams(\SimpleXMLElement $elem, VendorModelOffer $offer)
    {
        foreach ($elem->add_param as $param) {
            $this->addParam($param, $offer);
        }
    }

    protected function setCategoryId(\SimpleXMLElement $elem, Offer $offer, Shop $shop)
    {
        $categoryId = (string)$elem;

        if ($shop->getCategory($categoryId)) {
            $offer->setCategory($shop->getCategory($categoryId));
        }
    }

    protected function setCurrencyId(\SimpleXMLElement $elem, Offer $offer, Shop $shop)
    {
        $currencyId = $this->fixCurrency((string)$elem);

        if ($shop->getCurrency($currencyId)) {
            $offer->setCurrency($shop->getCurrency($currencyId));
        }
    }

    protected function loadElementXml()
    {
        $xml = $this->xmlReader->readOuterXml();

        return simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?>' . $xml);
    }

    private function camelize($field)
    {
        return strtr(ucwords(strtr($field, array('_' => ' ', '.' => '_ '))), array(' ' => ''));
    }

    private function fixCurrency($id)
    {
        $id = strtoupper($id);
        if ('RUR' === $id) {
            $id = 'RUB';
        }
        return $id;
    }
}
