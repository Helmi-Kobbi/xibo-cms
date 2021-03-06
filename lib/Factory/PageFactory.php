<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */


namespace Xibo\Factory;

use Xibo\Entity\Page;
use Xibo\Helper\SanitizerService;
use Xibo\Service\LogServiceInterface;
use Xibo\Storage\StorageServiceInterface;
use Xibo\Support\Exception\NotFoundException;

/**
 * Class PageFactory
 * @package Xibo\Factory
 */
class PageFactory extends BaseFactory
{
    /**
     * Construct a factory
     * @param StorageServiceInterface $store
     * @param LogServiceInterface $log
     * @param SanitizerService $sanitizerService
     */
    public function __construct($store, $log, $sanitizerService)
    {
        $this->setCommonDependencies($store, $log, $sanitizerService);
    }

    /**
     * Create empty
     * @return Page
     */
    public function create()
    {
        return new Page($this->getStore(), $this->getLog());
    }

    /**
     * Get by ID
     * @param int $pageId
     * @return Page
     * @throws NotFoundException if the page cannot be resolved from the provided route
     */
    public function getById($pageId)
    {
        $pages = $this->query(null, ['pageId' => $pageId, 'disableUserCheck' => 1]);

        if (count($pages) <= 0)
            throw new NotFoundException('Unknown Route');

        return $pages[0];
    }

    /**
     * Get by Name
     * @param string $page
     * @return Page
     * @throws NotFoundException if the page cannot be resolved from the provided route
     */
    public function getByName($page)
    {
        $pages = $this->query(null, array('name' => $page, 'disableUserCheck' => 1));

        if (count($pages) <= 0)
            throw new NotFoundException('Unknown Route');

        return $pages[0];
    }

    /**
     * @return Page[]
     * @throws NotFoundException
     */
    public function getForHomepage()
    {
        return $this->query(null, ['asHome' => 1]);
    }

    /**
     * @param null $sortOrder
     * @param array $filterBy
     * @return Page[]
     * @throws NotFoundException
     */
    public function query($sortOrder = null, $filterBy = [])
    {
        $parsedBody = $this->getSanitizer($filterBy);

        if ($sortOrder == null) {
            $sortOrder = ['name'];
        }

        $entries = [];
        $params = [];
        $sql = 'SELECT pageId, name, title, asHome FROM `pages` WHERE 1 = 1 ';

        // Logged in user view permissions
        $this->viewPermissionSql('Xibo\Entity\Page', $sql, $params, 'pageId', null, $filterBy);

        if ($parsedBody->getString('name') != null) {
            $params['name'] = $parsedBody->getString('name');
            $sql .= ' AND `name` = :name ';
        }

        if ($parsedBody->getInt('pageId') !== null) {
            $params['pageId'] = $parsedBody->getInt('pageId');
            $sql .= ' AND `pageId` = :pageId ';
        }

        if ($parsedBody->getInt('asHome') !== null) {
            $params['asHome'] = $parsedBody->getInt('asHome');
            $sql .= ' AND `asHome` = :asHome ';
        }

        // Sorting?
        $sql .= 'ORDER BY ' . implode(',', $sortOrder);



        foreach ($this->getStore()->select($sql, $params) as $row) {
            $entries[] = $this->create()->hydrate($row);
        }

        return $entries;
    }
}