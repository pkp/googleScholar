<?php

/**
 * @file plugins/generic/googleScholar/GoogleScholarPlugin.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GoogleScholarPlugin
 *
 * @ingroup plugins_generic_googleScholar
 *
 * @brief Inject Google Scholar meta tags into submission views to facilitate indexing.
 */

namespace APP\plugins\generic\googleScholar;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\citation\CitationDAO;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class GoogleScholarPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('ArticleHandler::view', [&$this, 'submissionView']);
                Hook::add('PreprintHandler::view', [&$this, 'submissionView']);
            }
            return true;
        }
        return false;
    }

    /**
     * Get the name of the settings file to be installed on new context
     * creation.
     *
     * @return string
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Inject Google Scholar metadata into submission landing page view
     *
     * @param string $hookName
     * @param array $args
     *
     * @return boolean
     */
    public function submissionView($hookName, $args)
    {
        $application = Application::get();
        $applicationName = $application->getName();
        /** @var Request */
        $request = $args[0];
        if ($applicationName == 'ojs2') {
            $issue = $args[1];
            $submission = $args[2];
            $submissionPath = 'article';
        }
        if ($applicationName == 'ops') {
            $submission = $args[1];
            $submissionPath = 'preprint';
        }
        /** @var Submission $submission */
        $requestArgs = $request->getRequestedArgs();
        $context = $request->getContext();

        // Only add Google Scholar metadata tags to the canonical URL for the latest version
        // See discussion: https://github.com/pkp/pkp-lib/issues/4870
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('googleScholarRevision', '<meta name="gs_meta_revision" content="1.1"/>');

        // Context identification
        if ($applicationName == 'ojs2') {
            $templateMgr->addHeader('googleScholarJournalTitle', '<meta name="citation_journal_title" content="' . htmlspecialchars($context->getName($context->getPrimaryLocale())) . '"/>');
            if (($abbreviation = $context->getData('abbreviation', $context->getPrimaryLocale())) || ($abbreviation = $context->getData('acronym', $context->getPrimaryLocale()))) {
                $templateMgr->addHeader('googleScholarJournalAbbrev', '<meta name="citation_journal_abbrev" content="' . htmlspecialchars($abbreviation) . '"/>');
            }
            if (($issn = $context->getData('onlineIssn')) || ($issn = $context->getData('printIssn')) || ($issn = $context->getData('issn'))) {
                $templateMgr->addHeader('googleScholarIssn', '<meta name="citation_issn" content="' . htmlspecialchars($issn) . '"/> ');
            }
        }
        if ($applicationName == 'ops') {
            $templateMgr->addHeader('googleScholarPublisher', '<meta name="citation_publisher" content="' . htmlspecialchars($context->getName($context->getPrimaryLocale())) . '"/>');
        }

        $publication = $submission->getCurrentPublication();
        $publicationLocale = $publication->getData('locale');
        $submissionBestId = $submission->getBestId();

        // Contributors
        $authors = $publication->getData('authors');
        foreach ($authors as $i => $author) {
            $templateMgr->addHeader('googleScholarAuthor' . $i, '<meta name="citation_author" content="' . htmlspecialchars($author->getFullName(false, false, $publicationLocale)) . '"/>');
            if ($affiliation = htmlspecialchars($author->getLocalizedData('affiliation', $publicationLocale))) {
                $templateMgr->addHeader('googleScholarAuthor' . $i . 'Affiliation', '<meta name="citation_author_institution" content="' . $affiliation . '"/>');
            }
        }

        // Submission title
        $templateMgr->addHeader('googleScholarTitle', '<meta name="citation_title" content="' . htmlspecialchars($publication->getLocalizedFullTitle($publicationLocale)) . '"/>');

        $templateMgr->addHeader('googleScholarLanguage', '<meta name="citation_language" content="' . htmlspecialchars(substr($publicationLocale, 0, 2)) . '"/>');

        // Submission publish date and issue information
        if ($applicationName == 'ojs2') {
            if (($datePublished = $publication->getData('datePublished')) && (!$issue || !$issue->getYear() || $issue->getYear() == date('Y', strtotime($datePublished)))) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            } elseif ($issue && $issue->getYear()) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . htmlspecialchars($issue->getYear()) . '"/>');
            } elseif ($issue && ($datePublished = $issue->getDatePublished())) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            }
            if ($issue) {
                if ($issue->getShowVolume()) {
                    $templateMgr->addHeader('googleScholarVolume', '<meta name="citation_volume" content="' . htmlspecialchars($issue->getVolume()) . '"/>');
                }
                if ($issue->getShowNumber()) {
                    $templateMgr->addHeader('googleScholarNumber', '<meta name="citation_issue" content="' . htmlspecialchars($issue->getNumber()) . '"/>');
                }
            }
            if ($publication->getData('pages')) {
                if ($startPage = $publication->getStartingPage()) {
                    $templateMgr->addHeader('googleScholarStartPage', '<meta name="citation_firstpage" content="' . htmlspecialchars($startPage) . '"/>');
                }
                if ($endPage = $publication->getEndingPage()) {
                    $templateMgr->addHeader('googleScholarEndPage', '<meta name="citation_lastpage" content="' . htmlspecialchars($endPage) . '"/>');
                }
            }
        }
        if ($applicationName == 'ops') {
            if ($datePublished = $publication->getData('datePublished')) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_online_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            }
        }

        // DOI
        if ($doi = $publication->getDoi()) {
            $templateMgr->addHeader('googleScholarPublicationDOI', '<meta name="citation_doi" content="' . htmlspecialchars($doi) . '"/>');
        }
        // URN
        foreach ((array) $templateMgr->getTemplateVars('pubIdPlugins') as $pubIdPlugin) {
            if ($pubId = $publication->getStoredPubId($pubIdPlugin->getPubIdType())) {
                $templateMgr->addHeader('googleScholarPubId' . $pubIdPlugin->getPubIdDisplayType(), '<meta name="citation_' . htmlspecialchars(strtolower($pubIdPlugin->getPubIdDisplayType())) . '" content="' . htmlspecialchars($pubId) . '"/>');
            }
        }

        // Abstract URL
        $templateMgr->addHeader('googleScholarHtmlUrl', '<meta name="citation_abstract_html_url" content="' . $request->url(null, $submissionPath, 'view', [$submissionBestId]) . '"/>');

        // Abstract
        if ($abstract = $publication->getLocalizedData('abstract', $publicationLocale)) {
            $templateMgr->addHeader('googleScholarAbstract', '<meta name="citation_abstract" xml:lang="' . htmlspecialchars(substr($publicationLocale, 0, 2)) . '" content="' . htmlspecialchars(strip_tags($abstract)) . '"/>');
        }

        // Subjects
        if ($subjects = $publication->getData('subjects')) {
            foreach ($subjects as $locale => $localeSubjects) {
                foreach ($localeSubjects as $i => $subject) {
                    $templateMgr->addHeader('googleScholarSubject' . $i++, '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($subject) . '"/>');
                }
            }
        }

        // Keywords
        if ($keywords = $publication->getData('keywords')) {
            foreach ($keywords as $locale => $localeKeywords) {
                foreach ($localeKeywords as $i => $keyword) {
                    $templateMgr->addHeader('googleScholarKeyword' . $i++, '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($keyword) . '"/>');
                }
            }
        }

        // Galley links
        $galleys = $publication->getData('galleys');
        foreach ($galleys as $i => $galley) {
            $submissionFileId = $galley->getData('submissionFileId');
            if ($submissionFileId && $submissionFile = Repo::submissionFile()->get($submissionFileId)) {
                if ($submissionFile->getData('mimetype') == 'application/pdf') {
                    $templateMgr->addHeader('googleScholarPdfUrl' . $i++, '<meta name="citation_pdf_url" content="' . $request->url(null, $submissionPath, 'download', [$submissionBestId, $galley->getBestGalleyId()]) . '"/>');
                } elseif ($submissionFile->getData('mimetype') == 'text/html') {
                    $templateMgr->addHeader('googleScholarHtmlUrl' . $i++, '<meta name="citation_fulltext_html_url" content="' . $request->url(null, $submissionPath, 'view', [$submissionBestId, $galley->getBestGalleyId()]) . '"/>');
                }
            }
        }

        // Citations
        $outputReferences = [];
        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var CitationDAO $citationDao */
        $parsedCitations = $citationDao->getByPublicationId($publication->getId());
        while ($citation = $parsedCitations->next()) {
            $outputReferences[] = $citation->getRawCitation();
        }
        Hook::call('GoogleScholarPlugin::references', [&$outputReferences, $submission->getId()]);

        foreach ($outputReferences as $i => $outputReference) {
            $templateMgr->addHeader('googleScholarReference' . $i++, '<meta name="citation_reference" content="' . htmlspecialchars($outputReference) . '"/>');
        }

        return false;
    }

    /**
     * Get the display name of this plugin
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.googleScholar.name');
    }

    /**
     * Get the description of this plugin
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.googleScholar.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\googleScholar\GoogleScholarPlugin', '\GoogleScholarPlugin');
}
