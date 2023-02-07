<?php

/**
 * @file plugins/generic/googleScholar/GoogleScholarPlugin.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GoogleScholarPlugin
 * @ingroup plugins_generic_googleScholar
 *
 * @brief Inject Google Scholar meta tags into submission views to facilitate indexing.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
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
        $requestArgs = $request->getRequestedArgs();
        $context = $request->getContext();

        // Only add Google Scholar metadata tags to the canonical URL for the latest version
        // See discussion: https://github.com/pkp/pkp-lib/issues/4870
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return;
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
        $submissionBestId = $publication->getData('urlPath') ?? $submission->getId();

        // Contributors
        foreach ($publication->getData('authors') as $i => $author) {
            $templateMgr->addHeader('googleScholarAuthor' . $i, '<meta name="citation_author" content="' . htmlspecialchars($author->getFullName(false)) . '"/>');
            if ($affiliation = htmlspecialchars($author->getLocalizedData('affiliation', $publicationLocale))) {
                $templateMgr->addHeader('googleScholarAuthor' . $i . 'Affiliation', '<meta name="citation_author_institution" content="' . $affiliation . '"/>');
            }
        }

        // Submission title
        $templateMgr->addHeader('googleScholarTitle', '<meta name="citation_title" content="' . htmlspecialchars($publication->getLocalizedFullTitle($publicationLocale)) . '"/>');
        $templateMgr->addHeader('googleScholarLanguage', '<meta name="citation_language" content="' . htmlspecialchars(substr($publicationLocale, 0, 2)) . '"/>');

        // Submission publish date and issue information
        if ($applicationName == 'ojs2') {
            if ($submission instanceof Submission && ($datePublished = $publication->getData('datePublished')) && (!$issue || !$issue->getYear() || $issue->getYear() == date('Y', strtotime($datePublished)))) {
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
            $templateMgr->addHeader('googleScholarDate', '<meta name="citation_online_date" content="' . date('Y/m/d', strtotime($publication->getData('datePublished'))) . '"/>');
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
        $i = 0;
        $submissionSubjectDao = DAORegistry::getDAO('SubmissionSubjectDAO');
        /** @var SubmissionSubjectDAO $submissionSubjectDao */
        if ($subjects = $submissionSubjectDao->getSubjects($publication->getId(), [$publicationLocale])) {
            foreach ($subjects as $locale => $subjectLocale) {
                foreach ($subjectLocale as $gsKeyword) {
                    $templateMgr->addHeader('googleScholarSubject' . $i++, '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($gsKeyword) . '"/>');
                }
            }
        }

        // Keywords
        $i = 0;
        $submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
        /** @var SubmissionKeywordDAO $submissionKeywordDao */
        if ($keywords = $submissionKeywordDao->getKeywords($publication->getId(), [$publicationLocale])) {
            foreach ($keywords as $locale => $keywordLocale) {
                foreach ($keywordLocale as $gsKeyword) {
                    $templateMgr->addHeader('googleScholarKeyword' . $i++, '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($gsKeyword) . '"/>');
                }
            }
        }

        // Galley links
        $i = 0;
        if ($submission instanceof Submission) {
            foreach ($publication->getData('galleys') as $galley) {
                $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
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

        if (!empty($outputReferences)) {
            $i = 0;
            foreach ($outputReferences as $outputReference) {
                $templateMgr->addHeader('googleScholarReference' . $i++, '<meta name="citation_reference" content="' . htmlspecialchars($outputReference) . '"/>');
            }
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
