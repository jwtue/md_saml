<?php
declare(strict_types=1);

namespace Mediadreams\MdSaml\Service;

/**
 *
 * This file is part of the Extension "md_saml" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2022 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Class SettingsService
 * @package Mediadreams\MdSaml\Service
 */
class SettingsService implements SingletonInterface
{
    protected $inCharge = false;
    protected $extSettings = null;

    public function setInCharge(bool $inCharge): void
    {
        $this->inCharge = $inCharge;
    }

    public function getInCharge(): bool
    {
        return $this->inCharge;
    }

    public function isFrontendLoginActive()
    {
        $extSettings = $this->getSettings('fe');
        return filter_var($extSettings['fe_users']['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function useFrontendAssertionConsumerServiceAuto(string $path)
    {
        $extSettings = $this->getSettings('fe');
        $auto = filter_var($extSettings['fe_users']['saml']['sp']['assertionConsumerService']['auto'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($auto && $this->isFrontendLoginActive()) {
            $assertionConsumerServiceUrl = $extSettings['fe_users']['saml']['sp']['assertionConsumerService']['url'] ?? '/';
            return $path == $assertionConsumerServiceUrl && $_POST['SAMLResponse'];
        }
        return false;
    }

    /**
     * Return settings
     *
     * @param string $loginType Can be 'FE' or 'BE'
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    public function getSettings(string $loginType): array
    {
        if ($this->extSettings === null) {
            // Backend mode, no TSFE loaded
            if (!isset($GLOBALS['TSFE'])) {
                $typoScriptSetup = $this->getTypoScriptSetup($this->getRootPageId());
                $this->extSettings = $typoScriptSetup['plugin']['tx_mdsaml']['settings'];
            } else {
                /** @var ConfigurationManager $configurationManager */
                $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
                $this->extSettings = $configurationManager->getConfiguration(
                    ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                    'Mdsaml',
                    ''
                );
            }
            if (count($this->extSettings) == 0) {
                throw new \RuntimeException('The TypoScript of ext:md_saml was not loaded.', 1648151884);
            }
        }

        // Merge settings according to given context (frontend or backend)
        $this->extSettings['saml'] = array_replace_recursive($this->extSettings['saml'], $this->extSettings[mb_strtolower($loginType) . '_users']['saml']);

        // Add base url
        $this->extSettings['saml']['baseurl'] = $this->extSettings['mdsamlSpBaseUrl'];
        $this->extSettings['saml']['sp']['entityId'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['entityId'];
        $this->extSettings['saml']['sp']['assertionConsumerService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['assertionConsumerService']['url'];
        $this->extSettings['saml']['sp']['singleLogoutService']['url'] = $this->extSettings['saml']['baseurl'] . $this->extSettings['saml']['sp']['singleLogoutService']['url'];

        $this->extSettings = $this->convertBooleans($this->extSettings);

        return $this->extSettings;
    }

    /**
     * Convert booleans to real booleans
     *
     * @param array $settings
     * @return array
     */
    private function convertBooleans(array $settings): array
    {
        array_walk_recursive(
            $settings,
            function (&$value) {
                if ($value === 'true') {
                    $value = true;
                } else {
                    if ($value === 'false') {
                        $value = false;
                    }
                }
            }
        );

        return $settings;
    }

    /**
     * Get root page ID according to calling url
     *
     * @return int|null
     */
    private function getRootPageId(): ?int
    {
        $siteUrl = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $allsites = $siteFinder->getAllSites();

        /** @var \TYPO3\CMS\Core\Site\Entity\Site $site */
        foreach ($allsites as $site) {
            if ($site->getBase()->getHost() == $siteUrl) {
                return $site->getRootPageId();
            }
        }

        throw new \RuntimeException('The site configuration could not be resolved.', 1648646492);
    }

    /**
     * Get TypoScript setup
     *
     * @param int $pageId
     * @return array
     */
    private function getTypoScriptSetup(int $pageId)
    {
        $template = GeneralUtility::makeInstance(TemplateService::class);
        $template->tt_track = false;
        $rootline = GeneralUtility::makeInstance(
            RootlineUtility::class, $pageId
        )->get();
        $template->runThroughTemplates($rootline, 0);
        $template->generateConfig();
        $typoScriptSetup = $template->setup;

        $typoScriptSetup = GeneralUtility::removeDotsFromTS($typoScriptSetup);

        return $typoScriptSetup;
    }
}
