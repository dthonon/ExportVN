<?php
/**
 * Download biolovision data and store in json files for further analysis
 *
 * PHP version 5
 *
 * Copyright (c) 2016 Daniel Thonon <d.thonon9@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation and/or
 * other materials provided with the distribution.
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 'AS IS' AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA,
 * OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 *
 */
require_once 'log4php/Logger.php';

// Configure root logger
Logger::configure('config.xml');

/**
 * Provide download functions on a specific table.
 *
 */
class DownloadTable
{
    /** Holds the Logger. */
    private $log;

    /** Holds the VisioNature site */
    private $site;

    /** Holds the table name */
    private $table;

    /** Holds the usename and password. */
    private $user_email;
    private $user_pw;

    /** Holds the file storage directory. */
    private $fileStore;

    /** Holds the max number of downloads (limit for debug). */
    private $maxDowload;

    /** Count number of download errors. */
    private $nbError;

    /** Constructor stores parameters. */
    public function __construct($site, $user_email, $user_pw, $table, $fileStore, $maxDowload = 10)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->site = $site;
        $this->user_email = $user_email;
        $this->user_pw = $user_pw;
        $this->table = $table;
        $this->fileStore = $fileStore;
        $this->maxDowload = $maxDowload;
        $this->nbError = 0;
    }

    function download($oauth)
    {
        $this->log->info(_('Téléchargement et stockage des ') . $this->table);

        $requestURI = $this->site . 'api/' . $this->table;

        $params = array(
            'user_pw' => $this->user_pw,
            'user_email' => $this->user_email
        );

        $i = 1;

        do {
            // Get data
            if($this->log->isTraceEnabled()) {
                $oauth->enableDebug();
            } else {
                $oauth->disableDebug();
            }
            try {
                $this->log->debug(_('Demande de ') . $this->table . ' n° ' . $i . ', API = ' . $requestURI);
                $oauth->fetch($requestURI, $params, OAUTH_HTTP_METHOD_GET);
                // $this->log->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $requestURI));
                $this->log->trace(_('Réception des données'));
                $response = $oauth->getLastResponse();
                $info = $oauth->getLastResponseInfo();
                $info['url'] = $requestURI . '?xxx';
                $this->log->trace(_('Code retour ') . print_r($info, true));
                $respHead = $oauth->getLastResponseHeaders();
                $this->log->trace($respHead);
                $pageNum = preg_match('/pagination_key: (.*)/', $respHead, $pageKey);
                if ($pageNum == 1) {
                    $key = rtrim($pageKey[1]);
                    $this->log->debug(_('Reçu clé = |') . $key . '|');
                } else {
                    $key = '';
                    $this->log->debug(_('Reçu sans clé'));
                }

                $data = json_decode($response, true);
                $this->log->debug(_('Reçu ') . count($data['data']) . _(' élements'));
                if ((count($data['data']) == 0) || ($pageNum == 0)) {
                    $this->log->debug(_('Fin de réception'));
                    break;
                }
                file_put_contents(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $i . '.json', $response);
                // $this->log->trace(print_r($data['data'], true));

                $params = array(
                    'user_pw' => $this->user_pw,
                    'user_email' => $this->user_email,
                    'pagination_key' => $key
                );
                $i += 1;
            } catch (OAuthException $oauthException) {
                $this->nbError += 1;
                $response = $oauth->getLastResponse();
                $jsonError = json_decode($oauthException->lastResponse, true);
                $this->log->error(_('Erreur de réception numéro : ') . $this->nbError . _(', code : ') . var_export($jsonError, true));
                $info = $oauth->getLastResponseInfo();
                $info['url'] = $requestURI . '?xxx';
                $this->log->error(_('Code retour ') . print_r($info, true));
                $this->log->error(_('Message d\'erreur : ') . $oauthException->getMessage());
                if ($this->nbError > 5) {
                    $this->log->fatal(_('Arrêt après 5 erreurs'));
                    break;
                }
                sleep(10); // Wait before next request
            }
        } while ($i < $this->maxDowload); // Limit to requests, to avoid bug infinite loop
    }


}

/**
 * Download and store taxo_groups
 *
 * name: storeTaxoGroups
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $options
 *            Options for user/password/...
 * @param void $oauth
 *            oauth acces to biolovision site
 * @return $taxo_lits
 *              list of taxo groups available
 * @author Daniel Thonon
 *
 */
function storeTaxoGroups($logger, $options, $oauth)
{
    $requestURI = $options['site'] . 'api/taxo_groups';

    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );

    $taxoList = array();
    $i = 1;
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug(_('Demande de taxo_groups ') . $i);
        $logger->trace(_(' => params:') . print_r($params, TRUE));
        $oauth->fetch($requestURI, $params, OAUTH_HTTP_METHOD_GET);
        // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $requestURI));
        $logger->trace('Réception des données');
        $response = $oauth->getLastResponse();
        // $logger->trace(print_r($oauth->getLastResponseInfo(), true));
        $respHead = $oauth->getLastResponseHeaders();
        // $logger->trace($respHead);
        $pageNum = preg_match('/pagination_key: (.*)/', $respHead, $pageKey);
        // $logger->trace('Page = ' . $pageNum . ', key = |' . rtrim($pageKey[1]) . '|');
        $data = json_decode($response, true);
        $logger->debug(_('Reçu ') . count($data['data']) . _(' élements'));
        if (count($data['data']) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/taxo_groups_' . $i . '.json', $response);

        foreach ($data['data'] as $key => $value) {
            // $logger->trace(print_r($data['data'][$key], true));
            $taxo = $data['data'][$key];
            $logger->trace(_('Groupe taxonomique : ') . $taxo['id'] . ' = ' . $taxo['name'] . _(', access = ') . $taxo['access_mode']);
            if ($taxo['access_mode'] != 'none') {
                $logger->info(_('Taxon à télécharger : ') . $taxo['id'] . ' = ' . $taxo['name'] . _(', access = ') . $taxo['access_mode']);
                $taxoList[] = $taxo['id'];
            }
        }

        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 10); // Limit to 10 request, to avoid bug infinite loop

    return $taxoList;
}

/**
 * Download and store observations
 *
 * name: storeObservations
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $options
 *            Options for user/password/...
 * @param void $oauth
 *            oauth acces to biolovision site
 * @return void
 * @author Daniel Thonon
 *
 */
function storeObservations($logger, $options, $oauth)
{
    $requestURI = $options['site'] . 'api/observations';

    // First, request list of taxo groups enabled
    // Note : as side effect, the taxo_group file is created here
    $taxoList = storeTaxoGroups($logger, $options, $oauth);
    // $taxoList = array(22);

    $i = 1; // Compteur de demandes

    // Loop on taxo groups, starting from the end to finish with birds (largest set)
    foreach (array_reverse($taxoList) as $idTaxo) {
        $logger->info(_('Demande des observations du groupe taxonomique = ') . $idTaxo);
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'id_taxo_group' => $idTaxo
        );

        $nbError = 0; // Error counter to stop if to many consecutive errors
        do {
            // Get data
            if($logger->isTraceEnabled()) {
                $oauth->enableDebug();
            } else {
                $oauth->disableDebug();
            }
            $logger->debug(_('Demande d\'observations ') . $i . _(', groupe taxonomique ') . $idTaxo);
            $logger->trace(_(' => params : ') . print_r($params, TRUE));
            try {
                $oauth->fetch($requestURI, $params, OAUTH_HTTP_METHOD_GET);
                // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $requestURI));
                $logger->trace(_('Réception des données'));
                $response = $oauth->getLastResponse();
                // $logger->trace(_('Réponse HTTP ') . print_r($oauth->getLastResponseInfo(), true));
                $respHead = $oauth->getLastResponseHeaders();
                // $logger->trace(_('Code HTTP ')$respHead);
                $pageNum = preg_match('/pagination_key: (.*)/', $respHead, $pageKey);
                $logger->debug(_('Reçu page = ') . $pageNum . _(', clé = |') . rtrim($pageKey[1]) . '|');
                $data = json_decode($response, true);

                $sightings = (array_key_exists('sightings', $data['data'])) ? count($data['data']['sightings']) : 0;
                $forms = (array_key_exists('forms', $data['data'])) ? count($data['data']['forms']) : 0;
                $logger->debug(_('Lu ') . $sightings . _(' élements sightings'));
                $logger->debug(_('Lu ') . $forms . _(' élements forms'));

                // Check if data received
                if ($sightings + $forms == 0) { // Empty file => exit
                    $logger->trace(_('Aucune données reçues '));
                    break;
                    // sleep(10);
                    // $params = array(
                    //     'user_pw' => $options['user_pw'],
                    //     'user_email' => $options['user_email'],
                    //     'id_taxo_group' => $idTaxo,
                    //     'pagination_key' => rtrim($pageKey[1])
                    // );
                } else { // Received some data
                    $logger->trace(_('Données reçues => stockage en json'));
                    file_put_contents(
                        getenv('HOME') . '/' . $options['file_store'] . '/observations_' . $i . '.json',
                        $response
                    );

                    $params = array(
                        'user_pw' => $options['user_pw'],
                        'user_email' => $options['user_email'],
                        'id_taxo_group' => $idTaxo,
                        'pagination_key' => rtrim($pageKey[1])
                    );
                    $i += 1;
                    $nbError = 0; // No error: reset counter
                }

            } catch (OAuthException $oauthException) {
                $nbError += 1;
                $response = $oauth->getLastResponse();
                $jsonError = json_decode($oauthException->lastResponse, true);
                $logger->error(_('Erreur de réception numéro : ') . $nbError . _(', code : ') . var_export($jsonError, true));
                $logger->error(print_r($oauth->getLastResponseInfo(), true));
                $logger->error(_('Message d\'erreur : ') . $oauthException->getMessage());
                if ($nbError > 5) {
                    $logger->fatal(_('Arrêt après 5 erreurs'));
                    break;
                }
                sleep(10); // Wait before next request
            };
        } while ($i < 500); // Limit to 500 request, to avoid bug infinite loop
    }
}

// ///////////////////////// Main ////////////////////////////////////
// Larger memory to handle observations
ini_set('memory_limit', '512M');

// Define command line options
$shortOpts = ''; // No short form options

$longOpts = array(
    'user_email:',      // Required: login email
    'user_pw:',         // Required: login password
    'pagination_key::', // Optional: pagination key to restart from
    'consumer_key:',    // Required: API key
    'consumer_secret:', // Required: API key
    'site:',            // Required: biolovision site to access
    'file_store:',      // Required: directory where downloaded json files are stored. Relative to $HOME
    'logging::'         // Optional: debugging messages
)
;

$options = getopt($shortOpts, $longOpts);

// Create logger and set level
$logger = Logger::getRootLogger();
$logger->setLevel(LoggerLevel::toLevel($options['logging']));

$logger->info(_('Début de l\'export - version : ') . file_get_contents('version.txt'));
// $logger->trace(var_export($options, true));

// Get authorization from Biolovision
try {
    $logger->trace(_('Obtention de oauth'));
    $oauth = new OAuth($options['consumer_key'], $options['consumer_secret'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
} catch (OAuthException $e) {
    $logger->fatal(print_r($e, true));
    die();
}

// Download and store local_admin_units in database
$local_admin_units = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'local_admin_units', $options['file_store'], 10);
$local_admin_units->download($oauth);
unset($local_admin_units);

// Download and store entities in database
$entities = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'entities', $options['file_store'], 10);
$entities->download($oauth);
unset($entities);

// Download and store export_organizations in database
$export_organizations = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'export_organizations', $options['file_store'], 10);
$export_organizations->download($oauth);
unset($export_organizations);

// Download and store families in database
$families = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'families', $options['file_store'], 10);
$families->download($oauth);
unset($families);

// Download and store grids in database
$grids = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'grids', $options['file_store'], 10);
$grids->download($oauth);
unset($grids);

// Download and store places in database
$places = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'places', $options['file_store'], 10);
$places->download($oauth);
unset($places);

// Download and store species in database
$species = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'species', $options['file_store'], 10);
$species->download($oauth);
unset($places);

// Download and store taxo_groups in database
$taxo_groups = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'taxo_groups', $options['file_store'], 10);
$taxo_groups->download($oauth);
unset($places);

// Download and store territorial_units in database
$territorial_units = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'territorial_units', $options['file_store'], 10);
$territorial_units->download($oauth);
unset($places);

// Download and store export observations
$logger->info(_('Téléchargement et stockage des observations'));
storeObservations($logger, $options, $oauth);
