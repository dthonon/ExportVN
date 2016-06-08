<?php
/**
 * Download biolovision data and store in json files for further analysis
 *
 * PHP version 5
 *
 * Copyright (c) 2015 Daniel Thonon <d.thonon9@gmail.com>
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
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
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
require ('log4php/Logger.php');

/**
 * Download and store places
 *
 * name: storePlaces
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
function storePlaces($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/places";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    
    $i = 1;
    
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug("Demande de données " . $i);
        $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
        // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
        $logger->trace("Réception des données");
        $response = $oauth->getLastResponse();
        // $logger->trace(print_r($oauth->getLastResponseInfo(), true));
        $respHead = $oauth->getLastResponseHeaders();
        // $logger->trace($respHead);
        $pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
        // $logger->trace("Page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
        $data = json_decode($response, true);
        $logger->debug("Reçu " . count($data["data"]) . " élements");
        if (count($data["data"]) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/places_' . $i . '.json', $response);
        $logger->trace(print_r($data["data"][1], true));
        
        
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 10); // Limit to 10 request, to avoid bug infinite loop
}

/**
 * Download and store local admin units
 *
 * name: storeAdminUnits
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
function storeAdminUnits($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/local_admin_units";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    
    $i = 1;
    // Get data
    $logger->debug("Demande de données " . $i);
    $logger->trace(" => params:" . print_r($params, TRUE));
    $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
    $logger->trace("Réception des données");
    $response = $oauth->getLastResponse();
    file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/admin_units_' . $i . '.json', $response);
    $data = json_decode($response, true);
    // $logger->trace(print_r($data["data"], true));
    $logger->debug("Reçu " . count($data["data"]) . " élements");
    
}

/**
 * Download and store entities
 *
 * name: storeEntities
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
function storeEntities($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/entities";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    
    $i = 1;
    // Get data
    try {
		$logger->trace("Demande de données");
		$oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
		$logger->trace("Réception des données");
		$response = $oauth->getLastResponse();
		file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/entities_' . $i . '.json', $response);
		$data = json_decode($response, true);
		// $logger->trace(print_r($data["data"], true));
		$logger->debug("Reçu " . count($data["data"]) . " élements");
	} catch (\OAuthException $oauthException) {
		$response = $oauth->getLastResponse();
		$logger->error(print_r($oauth->getLastResponseInfo(), true));
		$json_error = json_decode($oauthException->lastResponse, true);
		$logger->error("Erreur de réception : " . var_export($json_error, true));
		$logger->error("Message d'erreur : " . $oauthException->getMessage());
	};
    
}

/**
 * Download and store export organizations
 *
 * name: storeExport_Orgs
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
function storeExport_Orgs($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/export_organizations";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    
    $i = 1;
    // Get data
    $logger->trace("Demande de données");
    $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
    $logger->trace("Réception des données");
    $response = $oauth->getLastResponse();
    file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/export_orgs_' . $i . '.json', $response);
    $data = json_decode($response, true);
    // $logger->trace(print_r($data["data"], true));
    $logger->debug("Reçu " . count($data["data"]) . " élements");
    
}

/**
 * Download and store families
 *
 * name: storeFamilies
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
function storeFamilies($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/families";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    $i = 1;
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug("Demande de données " . $i);
        $logger->trace(" => params:" . print_r($params, TRUE));
        $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
        // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
        $logger->trace("Réception des données");
        $response = $oauth->getLastResponse();
        // $logger->trace(print_r($oauth->getLastResponseInfo(), true));
        $respHead = $oauth->getLastResponseHeaders();
        // $logger->trace($respHead);
        $pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
        // $logger->trace("Page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
        $data = json_decode($response, true);
        $logger->debug("Reçu " . count($data["data"]) . " élements");
        if (count($data["data"]) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/families_' . $i . '.json', $response);
        $logger->trace(print_r($data["data"][1], true));
              
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 10); // Limit to 10 request, to avoid bug infinite loop
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
			  list of taxo groups available
 * @author Daniel Thonon
 *        
 */
function storeTaxoGroups($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/taxo_groups";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
	
	$taxo_list = array();
    $i = 1;
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug("Demande de données " . $i);
        $logger->trace(" => params:" . print_r($params, TRUE));
        $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
        // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
        $logger->trace("Réception des données");
        $response = $oauth->getLastResponse();
        // $logger->trace(print_r($oauth->getLastResponseInfo(), true));
        $respHead = $oauth->getLastResponseHeaders();
        // $logger->trace($respHead);
        $pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
        // $logger->trace("Page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
        $data = json_decode($response, true);
        $logger->debug("Reçu " . count($data["data"]) . " élements");
        if (count($data["data"]) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/taxo_groups_' . $i . '.json', $response);

        foreach ($data["data"] as $key => $value) {
			// $logger->trace(print_r($data["data"][$key], true));
			$taxo = $data["data"][$key];
			$logger->info("Groupe taxonomique : " . $taxo["id"] . " = " . $taxo["name"] . ", access = " . $taxo["access_mode"]);
			if ($taxo["access_mode"] != "none") {
				$logger->info("Taxon à télécharger");
				$taxo_list[] = $taxo["id"];
			}
		}
		
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 10); // Limit to 10 request, to avoid bug infinite loop
	
	return $taxo_list;
}

/**
 * Download and store species
 *
 * name: storeSpecies
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
function storeSpecies($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/species";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    $i = 1;
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug("Demande de données " . $i);
        $logger->trace(" => params:" . print_r($params, TRUE));
        $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
        // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
        $logger->trace("Réception des données");
        $response = $oauth->getLastResponse();
        // $logger->trace(print_r($oauth->getLastResponseInfo(), true));
        $respHead = $oauth->getLastResponseHeaders();
        // $logger->trace($respHead);
        $pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
        // $logger->trace("Page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
        $data = json_decode($response, true);
        $logger->debug("Reçu " . count($data["data"]) . " élements");
        if (count($data["data"]) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/species_' . $i . '.json', $response);
        $logger->trace(print_r($data["data"][1], true));
        
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 100); // Limit requests, to avoid bug infinite loop
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeObservations($logger, $options, $oauth)
{
    $request_uri = $options['site'] . "api/observations";
	
	// First, request list of taxo groups enabled
	// Note : as side effect, the taxo_group file is created here
	$taxo_list = storeTaxoGroups($logger, $options, $oauth);
	
	$i = 1; // Compteur de demandes
	
	// Loop on taxo groups, starting from the end to finish with birds (largest set)
	foreach (array_reverse($taxo_list) as $id_taxo) {
		$logger->info("Demande de observations du groupe taxonomique = " . $id_taxo);
		$params = array(
			'user_pw' => $options['user_pw'],
			'user_email' => $options['user_email'],
			'id_taxo_group' => $id_taxo
		);

    		
		$nb_error = 0; // Error counter to stop if to many
		do {
			// Get data
			$oauth->enableDebug();
			$logger->debug("Demande de données " . $i . ", groupe taxo " . $id_taxo);
			$logger->trace(" => params:" . print_r($params, TRUE));
			try {
				$oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
				// $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
				$logger->trace("Réception des données");
				$response = $oauth->getLastResponse();
				$logger->trace(print_r($oauth->getLastResponseInfo(), true));
				$respHead = $oauth->getLastResponseHeaders();
				$logger->trace($respHead);
				$pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
				$logger->debug("Reçu page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
				$data = json_decode($response, true);
				$logger->debug("Reçu " . count($data["data"]["sightings"]) . " élements");
			} catch (\OAuthException $oauthException) {
				$nb_error += 1;
				$response = $oauth->getLastResponse();
				$logger->error("Erreur de réception numéro : " . $nb_error . ", code : " . var_export($json_error, true));
				$logger->error(print_r($oauth->getLastResponseInfo(), true));
				$json_error = json_decode($oauthException->lastResponse, true);
				$logger->error("Message d'erreur : " . $oauthException->getMessage());
				if ($nb_error > 5) {
					$logger->fatal("Arrêt après 5 erreurs");
					break;					
				}
				sleep(10); // Wait before next request
			};
			
			$sightings = (array_key_exists("sightings", $data["data"])) ? count($data["data"]["sightings"]) : 0;
			$forms = (array_key_exists("forms", $data["data"])) ? count($data["data"]["forms"]) : 0;
			$logger->debug("Lu " . $sightings . " élements sightings");
			$logger->debug("Lu " . $forms . " élements forms");
			
			// Empty file => exit
			if ($sightings + $forms == 0) {
				break;
			}
			file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/observations_' . $i . '.json', 
							  $response);
			
			$params = array(
				'user_pw' => $options['user_pw'],
				'user_email' => $options['user_email'],
				'id_taxo_group' => $id_taxo,
				'pagination_key' => rtrim($pageKey[1])
			);
			$i += 1;
		} while ($i < 500); // Limit to 500 request, to avoid bug infinite loop
	}
}

// ///////////////////////// Main ////////////////////////////////////
// Larger memory to handle observations
ini_set('memory_limit', '512M');

// Define command line options
$shortOpts = ""; // No short form options

$longOpts = array(
    "user_email:",      // Required: login email
    "user_pw:",         // Required: login password
    "pagination_key::", // Optional: pagination key to restart from
    "consumer_key:",    // Required: API key
    "consumer_secret:", // Required: API key
    "site:",            // Required: biolovision site to access
	"file_store:",      // Required: directory where downloaded json files are stored. Relative to $HOME
    "logging::"         // Optional: debugging messages
) 
;

$options = getopt($shortOpts, $longOpts);

// Create logger and set level
Logger::configure('config.xml');
$logger = Logger::getRootLogger();
$logger->setLevel(LoggerLevel::toLevel($options['logging']));

$logger->info("Début de l'export");
//$logger->trace(var_export($options, true));

// Get authorization from Biolovision
try {
	$logger->trace("Getting oauth\n");
	$oauth = new OAuth($options['consumer_key'], $options['consumer_secret'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
	// $oauth->enableDebug();
} catch (OAuthException $E) {
	$logger->error(print_r($E, true));
	die();
}

// Download and store export taxo_groups
// Note : also called by storeObservations, so no need to uncomment
// $logger->info("Téléchargement et stockage des 'taxo_groups'");
// $taxo_list = storeTaxoGroups($logger, $options, $oauth);
// foreach ($taxo_list as $id_taxo) {
	// $logger->trace("Groupe taxonomique = " . $id_taxo);
// }

// Download and store entities
$logger->info("Téléchargement et stockage des 'entities'");
storeEntities($logger, $options, $oauth);

// Download and store export organizations
$logger->info("Téléchargement et stockage des 'export_organizations'");
storeExport_Orgs($logger, $options, $oauth);

// Download and store places
$logger->info("Téléchargement et stockage des 'places'");
storePlaces($logger, $options, $oauth);

// Download and store admin units
$logger->info("Téléchargement et stockage des 'local admin units'");
storeAdminUnits($logger, $options, $oauth);

// Download and store export families
$logger->info("Téléchargement et stockage des 'families'");
storeFamilies($logger, $options, $oauth);

// Download and store export species
$logger->info("Téléchargement et stockage des 'species'");
storeSpecies($logger, $options, $oauth);

// Download and store export observations
$logger->info("Téléchargement et stockage des 'observations'");
storeObservations($logger, $options, $oauth);

$dbh = null;
?>
