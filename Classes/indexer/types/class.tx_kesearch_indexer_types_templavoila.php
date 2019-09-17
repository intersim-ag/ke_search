<?php
/***************************************************************
 *  Copyright notice
 *  (c) 2012 Stefan Froemken
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 * @author    Stefan Froemken
 * @package    TYPO3
 * @subpackage    tx_kesearch
 */
class tx_kesearch_indexer_types_templavoila extends tx_kesearch_indexer_types
{
    public $pids = 0;

    // this array contains all data of all pages
    public $pageRecords = array();

    // this array contains all data of all pages, but additionally with all available languages
    public $cachedPageRecords = array();
    public $sysLanguages = array();
    public $indexCTypes = array(
        'text',
        'textpic',
        'bullets',
        'table',
        'html',
        'header',
        'templavoila_pi1'
    );
    public $counter = 0;
    public $whereClauseForCType = '';
    public $templavoilaIsLoaded = false;

    // Name of indexed elements. Will be overwritten in content element indexer.
    public $indexedElementsName = 'TemplaVoila records';

    /**
     * @var tx_templavoila_pi1
     */
    public $tv;


    /**
     * Construcor of this object
     */
    public function __construct($pObj)
    {
        parent::__construct($pObj);

        $this->templavoilaIsLoaded = TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('templavoila');
        if ($this->templavoilaIsLoaded) {
            $enableFields = BackendUtility::BEenableFields('pages') . BackendUtility::deleteClause('pages');

            $row = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid,title', 'pages', 'pid = 0' . $enableFields);

            $GLOBALS['TT'] = new \TYPO3\CMS\Core\TimeTracker\NullTimeTracker();

            $GLOBALS['TSFE'] = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                'TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController',
                $GLOBALS['TYPO3_CONF_VARS'],
                $row['uid'],
                0
            );
            $GLOBALS['TSFE']->sys_page = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                'TYPO3\CMS\Frontend\Page\PageRepository'
            );
            $GLOBALS['TSFE']->sys_page->init(true);
            $GLOBALS['TSFE']->initTemplate();

            // Filling the config-array, first with the main "config." part
            if (is_array($GLOBALS['TSFE']->tmpl->setup['config.'])) {
                $GLOBALS['TSFE']->config['config'] = $GLOBALS['TSFE']->tmpl->setup['config.'];
            }

            // override it with the page/type-specific "config."
            if (is_array($GLOBALS['TSFE']->pSetup['config.'])) {
                \TYPO3\CMS\Core\Utility\ArrayUtility::mergeRecursiveWithOverrule(
                    $GLOBALS['TSFE']->config['config'],
                    $GLOBALS['TSFE']->pSetup['config.']
                );
            }

            // generate basic rootline
            $GLOBALS['TSFE']->rootLine = array(
                0 => array('uid' => $row['uid'], 'title' => $row['title'])
            );

            $this->tv = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('tx_templavoila_pi1');
        }

        $this->counter = 0;
        $cTypes = array();
        foreach ($this->indexCTypes as $value) {
            $cTypes[] = 'CType="' . $value . '"';
        }
        $this->whereClauseForCType = implode(' OR ', $cTypes);

        // get all available sys_language_uid records
        /** @var TranslationConfigurationProvider $translationProvider */
        $translationProvider = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
        $this->sysLanguages = $translationProvider->getSystemLanguages($pageId);
    }


    /**
     * This function was called from indexer object and saves content to index table
     * @return string content which will be displayed in backend
     */
    public function startIndexing()
    {
        if (!$this->templavoilaIsLoaded) {
            return 'TemplaVoila is not installed!';
        }

        // get all pages. Regardeless if they are shortcut, sysfolder or external link
        $indexPids = $this->getPagelist(
            $this->indexerConfig['startingpoints_recursive'],
            $this->indexerConfig['single_pages']
        );

        // add complete page record to list of pids in $indexPids
        $this->pageRecords = $this->getPageRecords($indexPids);

        // create a new list of allowed pids
        $indexPids = array_keys($this->pageRecords);

        // add tags to pages of doktype standard, advanced, shortcut and "not in menu"
        // add tags also to subpages of sysfolders (254), since we
        // don't want them to be excluded (see: http://forge.typo3.org/issues/49435)
        $where = ' (doktype = 1 OR doktype = 2 OR doktype = 4 OR doktype = 5 OR doktype = 254) ';

        // add the tags of each page to the global page array
        $this->addTagsToRecords($indexPids, $where);

        // loop through pids and collect page content and tags
        foreach ($indexPids as $uid) {
            if ($uid) {
                $this->getPageContent($uid);
            }
        }

        // show indexer content?
        $content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '": </b><br />'
            . count($this->pageRecords) . ' pages for TemplaVoila have been found for indexing.<br />' . "\n"
            . $this->counter . ' ' . $this->indexedElementsName . ' have been indexed.<br />' . "\n"
            . '</p>' . "\n";

        $content .= $this->showErrors();
        $content .= $this->showTime();

        return $content;
    }

    /**
     * get array with all pages
     * but remove all pages we don't want to have
     * additionally generates a cachedPageArray
     * @param array $uids Array with all page cols
     * @return array|mixed
     */
    public function getPageRecords(array $uids, $whereClause = '', $table = 'pages', $fields = 'pages.*')
    {
        $fields = '*';
        $table = 'pages';
        $where = 'uid IN (' . implode(',', $uids) . ')';

        // index only pages of doktype standard, advanced and "not in menu"
        // add also sysfolders (254) and shortlinks (4), we need this for the correct inheritage of
        // frontend user groups
        $where .= ' AND (doktype = 1 OR doktype = 2 OR doktype = 4 OR doktype = 5 OR doktype = 254) ';

        // index only pages which are searchable
        // index only page which are not hidden
        $where .= ' AND no_search <> 1 AND hidden=0';

        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields, $table, $where);

        $pages = array();
        while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
            $this->addLocalizedPagesToCache($row);
            $pages[$row['uid']] = $row;
        }
        return $pages;
    }


    /**
     * add localized page records to a cache/globalArray
     * This is much faster than requesting the DB for each tt_content-record
     * @param array $row
     * @return void
     */
    public function addLocalizedPagesToCache($row)
    {
        $this->cachedPageRecords[0][$row['uid']] = $row;
        foreach ($this->sysLanguages as $sysLang) {
            list($pageOverlay) = BackendUtility::getRecordsByField(
                'pages_language_overlay',
                'pid',
                $row['uid'],
                'AND sys_language_uid=' . (int)$sysLang['uid']
            );
            if ($pageOverlay) {
                $this->cachedPageRecords[$sysLang['uid']][$row['uid']] = $pageOverlay + $row;
            }
        }
    }

    /**
     * get content of current page and save data to db
     * @param integer $uid page-UID that has to be indexed
     * @return string
     */
    public function getPageContent($uid)
    {
        $pageContent = array();
        $additionalFields = '';

        $flex = $this->pageRecords[$uid]['tx_templavoila_flex'];
        if (empty($flex)) {
            return '';
        }
        $flex = TYPO3\CMS\Core\Utility\GeneralUtility::xml2array($flex);

        // TODO: Maybe I need a more detailed collection of retrieving CE UIDS
        $contentElementUids = array();
        if (!$this->indexerConfig['tvpath']) {
            $tvPaths = 'field_content';
        } else {
            $tvPaths = $this->indexerConfig['tvpath'];
        }

        $tvPaths = TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(',', $tvPaths);

        foreach ($tvPaths as $tvPath) {
            $contentElementUids[] = $flex['data']['sDEF']['lDEF'][$tvPath]['vDEF'];
        }

        $contentElementUids = TYPO3\CMS\Core\Utility\GeneralUtility::uniqueList(implode(',', $contentElementUids));

        if (empty($contentElementUids)) {
            return '';
        }

        // TODO: Maybe it's good to check comma seperated list for int values
        // get content elements for this page
        $fields = '*';
        $table = 'tt_content';
        $where = 'uid IN (' . $contentElementUids . ')';
        $where .= ' AND (' . $this->whereClauseForCType . ')';
        $where .= BackendUtility::BEenableFields($table);
        $where .= BackendUtility::deleteClause($table);

        // if indexing of content elements with restrictions is not allowed
        // get only content elements that have empty group restrictions
        if ($this->indexerConfig['index_content_with_restrictions'] != 'yes') {
            $where .= ' AND (fe_group = "" OR fe_group = "0") ';
        }


        $rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);
        if (count($rows)) {
            $this->counter++;

            foreach ($rows as $row) {
                // header
                // add header only if not set to "hidden"
                if ($row['header_layout'] != 100) {
                    $pageContent[$row['sys_language_uid']] .= strip_tags($row['header']) . "\n";
                }

                // bodytext
                $bodytext = $row['bodytext'];

                if ($row['CType'] == 'table') {
                    // replace table dividers with whitespace
                    $bodytext = str_replace('|', ' ', $bodytext);
                }
                if ($row['CType'] == 'templavoila_pi1') {
                    $bodytext = $this->tv->renderElement($row, 'tt_content');
                }
                // following lines prevents having words one after the other like: HelloAllTogether
                $bodytext = str_replace('<td', ' <td', $bodytext);
                $bodytext = str_replace('<br', ' <br', $bodytext);
                $bodytext = str_replace('<p', ' <p', $bodytext);
                $bodytext = str_replace('<li', ' <li', $bodytext);

                $bodytext = strip_tags($bodytext);

                $pageContent[$row['sys_language_uid']] .= $bodytext . "\n";
            }
        }

        // get Tags for current page
        $tags = $this->pageRecords[intval($uid)]['tags'];

        // make it possible to modify the indexerConfig via hook
        $indexerConfig = $this->indexerConfig;

        // hook for custom modifications of the indexed data, e. g. the tags
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyTemplaVoilaIndexEntry'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyTemplaVoilaIndexEntry'] as $_classRef) {
                $_procObj = &TYPO3\CMS\Core\Utility\GeneralUtility::getUserObj($_classRef);
                $_procObj->modifyPagesIndexEntry(
                    $uid,
                    $pageContent,
                    $tags,
                    $this->cachedPageRecords,
                    $additionalFields,
                    $indexerConfig
                );
            }
        }

        // store record in index table
        foreach ($pageContent as $langKey => $content) {
            $this->pObj->storeInIndex(
                $indexerConfig['storagepid'],                          // storage PID
                $this->cachedPageRecords[$langKey][$uid]['title'],     // page title
                'templavoila',                                         // content type
                $uid,                                                  // target PID: where is the single view?
                $content,
                // indexed content, includes the title (linebreak after title)
                $tags,                                                 // tags
                '',                                                    // typolink params for singleview
                '',                                                    // abstract
                $langKey,                                              // language uid
                $this->cachedPageRecords[$langKey][$uid]['starttime'], // starttime
                $this->cachedPageRecords[$langKey][$uid]['endtime'],   // endtime
                $this->cachedPageRecords[$langKey][$uid]['fe_group'],  // fe_group
                false,                                                 // debug only?
                $additionalFields                                      // additional fields added by hooks
            );
        }

        return '';
    }
}
