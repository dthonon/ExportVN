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
 * Gets taxo_groups list from file and returns the list of active taxo_groups
 *
 * name: getTaxoGroups
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $fileStore
 *            Where the file is stored
 * @return $taxo_list
 *              list of taxo groups active for this site
 * @author Daniel Thonon
 *
 */
function getTaxoGroups($logger, $fileStore)
{
    $logger->debug(_('Appel de getTaxoGroups'));

    // Read taxo_groups file
    $response = file_get_contents(getenv('HOME') . '/' . $fileStore . '/taxo_groups_1.json');
    $data = json_decode($response, true);

    $taxoList = array();
    foreach ($data['data'] as $key => $value) {
        // $logger->trace(print_r($data['data'][$key], true));
        $taxo = $data['data'][$key];
        $logger->trace(_('Groupe taxonomique : ') . $taxo['id'] . ' = ' . $taxo['name'] . _(', access = ') . $taxo['access_mode']);
        if ($taxo['access_mode'] != 'none') {
            $logger->info(_('Taxon à télécharger : ') . $taxo['id'] . ' = ' . $taxo['name'] . _(', access = ') . $taxo['access_mode']);
            $taxoList[] = $taxo['id'];
        }
    }

    return $taxoList;
}

/**
 * Gets local_admin_units list from file and returns the list of active local_admin_units
 *
 * name: getLocalAdminUnits
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $fileStore
 *            Where the file is stored
 * @return $localAdminUnitsList
 *              list of local_admin_units of the site
 * @author Daniel Thonon
 *
 */
function getLocalAdminUnits($logger, $fileStore)
{
    $logger->debug(_('Appel de getLocalAdminUnits'));

    // Read taxo_groups file
    $response = file_get_contents(getenv('HOME') . '/' . $fileStore . '/local_admin_units_1.json');
    $data = json_decode($response, true);

    $localAdminUnitsList = array();
    foreach ($data['data'] as $key => $value) {
        //$logger->trace(print_r($data['data'][$key], true));
        $commune = $data['data'][$key];
        $logger->trace(_('local_admin_units : ') . $commune['id'] . ' = ' . $commune['name']);
-       $localAdminUnitsList[] = $commune['id'];
    }

    return $localAdminUnitsList;
}

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
    private $maxDownload;

    /** Count number of download errors. */
    private $nbError;

    /** What type of subquery to use */
    private $by_list;

    /** Constants for different lists of subqueries */
    const NO_LIST = 0; // No subquery, just request all data
    const TAXO_LIST = 1; // Loop subquery on taxo_groups
    const ADMIN_UNITS_LIST = 2; // Loop subquery on local_admin_units (communes)

    /** Constructor stores parameters. */
    public function __construct($site, $user_email, $user_pw, $table, $fileStore,
                                $maxDownload = 10, $by_list = self::NO_LIST)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->site = $site;
        $this->user_email = $user_email;
        $this->user_pw = $user_pw;
        $this->table = $table;
        $this->fileStore = $fileStore;
        $this->maxDowload = $maxDownload;
        $this->by_list = $by_list;
        $this->nbError = 0;
    }

    function download($oauth)
    {
        $this->log->info(_('Téléchargement et stockage des ') . $this->table);

        /* What type of list is used to subquery ? */
        switch ($this->by_list) {
            case self::NO_LIST:
                $queryList = array(1);
                break;
           case self::TAXO_LIST:
                // First, request list of taxo groups enabled
                $queryList = getTaxoGroups($this->log, $this->fileStore);
                break;
           case self::ADMIN_UNITS_LIST:
                // First, request list of local_admin_units
                $queryList = getLocalAdminUnits($this->log, $this->fileStore);
                break;
           default:
                $this->by_list = self::NO_LIST;
                $queryList = array(1);
                break;
        }

        $requestURI = $this->site . 'api/' . $this->table;

        $i = 1;

        // Loop on query list, starting from the end to finish with birds (largest set for taxo list)
        foreach (array_reverse($queryList) as $idQuery) {
            /* What type of list is used to subquery ? */
            switch ($this->by_list) {
                case self::NO_LIST:
                    $this->log->info(_('Demande des ') . $this->table . _(' numéro ') . $i);
                    $params = array(
                        'user_pw' => $this->user_pw,
                        'user_email' => $this->user_email
                    );
                    break;
               case self::TAXO_LIST:
                    $this->log->info(_('Demande des ') . $this->table . _(' du taxo_group = ') . $idQuery . _(' numéro ') . $i);
                    $params = array(
                        'user_pw' => $this->user_pw,
                        'user_email' => $this->user_email,
                        'is_used' => 1,
                        'id_taxo_group' => $idQuery
                        );
                    break;
               case self::ADMIN_UNITS_LIST:
                    $this->log->info(_('Demande des ') . $this->table . _(' du local_admin_unit = ') . $idQuery . _(' numéro ') . $i);
                    $params = array(
                        'user_pw' => $this->user_pw,
                        'user_email' => $this->user_email,
                        'id_commune' => $idQuery
                    );
                    break;
            }

            do {
                // Get data
                if($this->log->isTraceEnabled()) {
                    $oauth->enableDebug();
                } else {
                    $oauth->disableDebug();
                }
                try {
                    $this->log->debug(_('Demande de ') . $this->table . ' n° ' . $i . ', API = ' . $requestURI);
                    $oauth->fetch($requestURI, $params, OAUTH_HTTP_METHOD_GET, array('Accept-Encoding' => 'gzip'));
                    // $this->log->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $requestURI, $params));
                    $this->log->trace(_('Réception des données'));
                    $info = $oauth->getLastResponseInfo();
                    $info['url'] = $requestURI . '?xxx';
                    $this->log->trace(_('LastResponseInfo: ') . print_r($info, true));
                    $respHead = $oauth->getLastResponseHeaders();
                    // $this->log->trace('LastResponseHeaders: ' . $respHead);
                    // $this->log->trace('LastResponse: ' . $oauth->getLastResponse());
                    if (isset($info['content_encoding']) && $info['content_encoding'] == 'gzip') {
                        if ($i == 1) {
                            $this->log->info('Réponse compressée par gzip');
                        }
                        $response = gzdecode($oauth->getLastResponse());
                    } else {
                        if ($i == 1) {
                            $this->log->info('Réponse noncompressée par gzip');
                        }
                        $response = $oauth->getLastResponse();
                    }

                    $this->log->trace(_('LastResponse: ') . substr($response, 1, 50));
                    $this->log->debug(_('Enregistrement dans ') . $this->table . '_' . $i . '.json');
                    file_put_contents(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $i . '.json', $response);
                    $i += 1;
                    $chunked = preg_match('/transfer-encoding: chunked/', $respHead, $chunk);
                    if (!$chunked) {
                        $this->log->debug(_('Fin de réception car pas de transfer-encoding: chunked/'));
                        break;
                    }

                    // Find pagination_key for further request
                    $pageNum = preg_match('/pagination_key: (.*)/', $respHead, $pageKey);
                    if ($pageNum == 1) {
                        $key = rtrim($pageKey[1]);
                        $this->log->debug(_('Reçu clé = |') . $key . '|');
                    } else {
                        $key = '';
                        $this->log->debug(_('Reçu sans clé'));
                    }

                    /* What type of list is used to subquery ? */
                    switch ($this->by_list) {
                        case self::NO_LIST:
                            $this->log->info(_('Demande des ') . $this->table . _(' numéro ') . $i);
                            $params = array(
                                'user_pw' => $this->user_pw,
                                'user_email' => $this->user_email,
                                'pagination_key' => $key
                            );
                            break;
                       case self::TAXO_LIST:
                            $this->log->info(_('Demande des ') . $this->table . _(' du taxo_group = ') . $idQuery . _(' numéro ') . $i);
                            $params = array(
                                'user_pw' => $this->user_pw,
                                'user_email' => $this->user_email,
                                'is_used' => 1,
                                'id_taxo_group' => $idQuery,
                                'pagination_key' => $key
                                );
                            break;
                       case self::ADMIN_UNITS_LIST:
                            $this->log->info(_('Demande des ') . $this->table . _(' du local_admin_unit = ') . $idQuery . _(' numéro ') . $i);
                            $params = array(
                                'user_pw' => $this->user_pw,
                                'user_email' => $this->user_email,
                                'id_commune' => $idQuery,
                                'pagination_key' => $key
                            );
                            break;
                    }

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


}


// ///////////////////////// Main ////////////////////////////////////
// Larger memory to handle observations
ini_set('memory_limit', '1024M');

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

// -------------------
// Organizational data
// -------------------
// Download and store entities in database
$entities = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'entities',
                              $options['file_store'], 10);
$entities->download($oauth);
unset($entities);

// Download and store export_organizations in database
$export_organizations = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'export_organizations',
                                          $options['file_store'], 10);
$export_organizations->download($oauth);
unset($export_organizations);


// --------------
// Taxonomic data
// --------------
// Download and store taxo_groups in database. Must be done first for other object to use latest version
$taxo_groups = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'taxo_groups',
                                 $options['file_store'], 10);
$taxo_groups->download($oauth);
unset($taxo_groups);

// Download and store families in database
$families = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'families',
                              $options['file_store'], 10);
$families->download($oauth);
unset($families);

// Download and store species in database, subquery by taxo_groups
$species = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'species',
                             $options['file_store'], 200, DownloadTable::TAXO_LIST);
$species->download($oauth);
unset($species);

// ------------------------
// Geographical information
// ------------------------
// Download and store territorial_units in database
// $territorial_units = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'territorial_units',
//                                        $options['file_store'], 10);
// $territorial_units->download($oauth);
// unset($territorial_units);
//
// // Download and store grids in database
// $grids = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'grids',
//                            $options['file_store'], 10);
// $grids->download($oauth);
// unset($grids);
//
// // Download and store local_admin_units in database. Must be done first for other object to use latest version
// $local_admin_units = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'local_admin_units',
//                                        $options['file_store'], 10);
// $local_admin_units->download($oauth);
// unset($local_admin_units);
//
// // Download and store places in database, subquery by local_admin_units
// $places = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'places',
//                             $options['file_store'], 1000, DownloadTable::ADMIN_UNITS_LIST);
// $places->download($oauth);
// unset($places);

// ----------------
// Observation data
// ----------------
// Download and store observations in database, subquery by taxo_groups
// $observations = new DownloadTable($options['site'], $options['user_email'], $options['user_pw'], 'observations',
//                                   $options['file_store'], 500, DownloadTable::TAXO_LIST);
// $observations->download($oauth);
// unset($observations);
