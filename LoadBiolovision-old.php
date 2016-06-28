<?php
/**
 * Download biolovision data and store in postgres database
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
 * Finds the type of the value, based on its format
 *
 * @param string $val
 *            The value to be parsed
 * @return string The ddl type of $val
 * @author Daniel Thonon
 *        
 */
function typeOfValue($val)
{
    if (preg_match('/^-?\d+$/', $val) == 1) {
        return 'integer';
    } elseif (preg_match('/^-?\d+\.\d+$/', $val) == 1) {
        return 'double precision';
    } else {
        return 'character varying';
    }
}

/**
 * Prepares the (name, type) part of the table creation DDL statement
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $data
 *            Data to parse to find the names and types
 * @return string The DDL statement (name type...)
 * @author Daniel Thonon
 *        
 */
function ddlNamesTypes($logger, $data)
{
    // Analyze first element to define DDL types
    $logger->trace("Analyse de l'élement: " . print_r($data[1], true));
    $ddl = array();
    reset($data[1]);
    // Find the types
    foreach ($data[1] as $key => $value) {
        $ddl[$key] = typeOfValue($value);
    }
    $logger->trace(print_r($ddl, true));
    return $ddl;
}

/**
 * Insert the rows in a table, creating new colums as needed
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $data
 *            Data to be iterated over to insert rows
 * @param void $dbh
 *            Database connection
 * @param string $table
 *            Name of the table for insertion
 * @param array $ddlNT
 *            Array of (name, type) of the DDL columns already created
 * @return array $ddlNT
 *         Array of (name, type) of the DDL columns modified if needed
 * @author Daniel Thonon
 *        
 */
function insertRows($logger, $data, $dbh, $table, $ddlNT)
{
    $logger->debug("Insertion des lignes dans " . $table);
    $dbh->beginTransaction();
    // Loop over each data element
    $nbLines = 0;
    reset($data);
    foreach ($data as $key => $val) {
        // $logger->trace("Analyse de l'élement pour insertion: " . print_r($val, true));
        $rowKeys = "(";
        $rowVals = "(";
        reset($val);
        foreach ($val as $k => $v) {
            // Check if column already exists in the table
            if (! array_key_exists($k, $ddlNT)) {
                $logger->debug("Colonne absente de la table : " . $k);
                // Close insert transaction before modifying table
                $dbh->commit();
                $ddlStmt = $k . " " . typeOfValue($v) . ";";
                $ddlStmt = "ALTER TABLE " . $table . $ddlStmt;
                $ddlNT[$k] = typeOfValue($v);
                $logger->debug("Modification de la table " . $ddlStmt);
                $dbh->exec($ddlStmt);
				// Open new insert transaction
                $dbh->beginTransaction();
           }
            $rowKeys .= $k . ",";
            $rowVals .= "'" . str_replace("'", "''", $v) . "'" . ",";
        }
        $rowKeys = substr($rowKeys, 0, - 1) . ")";
        $rowVals = substr($rowVals, 0, - 1) . ")";
        $inData = "INSERT INTO export." . $table . $rowKeys . " VALUES " . $rowVals;
        // $logger->trace($inData);
        $nbLines += $dbh->exec($inData) or die(print_r($dbh->errorInfo(), true));
    }
    $dbh->commit();
    $logger->debug($nbLines . " inserted into " . $table);
    return $ddlNT;
}

/**
 * Creates table after drop if exists
 *
 * @param void $logger
 *            Logger for debug message
 * @param array $data
 *            Data to be iterated over to create columns
 * @param void $dbh
 *            Database connection
 * @param string $table
 *            Name of the table for insertion
 * @return array $ddlNT
 *         Array of (name, type) of the DDL columns created
 * @author Daniel Thonon
 *        
 */
function createTable($logger, $data, $dbh, $table)
{
    // Delete if exists and create table
    $logger->debug("Suppression de la table " . $table);
    $dbh->exec("DROP TABLE IF EXISTS export." . $table);
    // Prepare the DDL statement based on the analyzed types
    $ddlNT = ddlNamesTypes($logger, $data);
    $ddlStmt = " (";
    foreach ($ddlNT as $k => $v) {
        $ddlStmt .= $k . " " . $v . ",";
    }
    $ddlStmt = substr($ddlStmt, 0, - 1) . ");";
    $ddlStmt = "CREATE TABLE export." . $table . $ddlStmt;
    $logger->debug("Création de la table " . $ddlStmt);
    $dbh->exec($ddlStmt);
    return $ddlNT;
}

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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storePlaces($logger, $options, $oauth, $dbh)
{
    $request_uri = $options['site'] . "api/places";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    
    $i = 1;
    $ddlNT = array();
    
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
        
        // Create table on first loop
        if ($i == 1) {
            $ddlNT = createTable($logger, $data["data"], $dbh, "places");
        }
        // Insert rows
        $ddlNT = insertRows($logger, $data["data"], $dbh, "places", $ddlNT);
        
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeAdminUnits($logger, $options, $oauth, $dbh)
{
    $request_uri = $options['site'] . "api/local_admin_units";
    
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
    file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/admin_units_' . $i . '.json', $response);
    $data = json_decode($response, true);
    // $logger->trace(print_r($data["data"], true));
    $logger->debug("Reçu " . count($data["data"]) . " élements");
    
    // Create table and insert data
    $ddlNT = createTable($logger, $data["data"], $dbh, "local_admin_units");
    $ddlNT = insertRows($logger, $data["data"], $dbh, "local_admin_units", $ddlNT);
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeEntities($logger, $options, $oauth, $dbh)
{
    $request_uri = $options['site'] . "api/entities";
    
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
    file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/entities_' . $i . '.json', $response);
    $data = json_decode($response, true);
    // $logger->trace(print_r($data["data"], true));
    $logger->debug("Reçu " . count($data["data"]) . " élements");
    
    // Create table and insert data
    $ddlNT = createTable($logger, $data["data"], $dbh, "entities");
    $ddlNT = insertRows($logger, $data["data"], $dbh, "entities", $ddlNT);
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeExport_Orgs($logger, $options, $oauth, $dbh)
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
    
    // Create table and insert data
    $ddlNT = createTable($logger, $data["data"], $dbh, "export_organizations");
    $ddlNT = insertRows($logger, $data["data"], $dbh, "export_organizations", $ddlNT);
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeFamilies($logger, $options, $oauth, $dbh)
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
        
        // Create table on first loop
        if ($i == 1) {
            $ddlNT = createTable($logger, $data["data"], $dbh, "families");
        }
        // Insert rows
        $ddlNT = insertRows($logger, $data["data"], $dbh, "families", $ddlNT);
        
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeTaxoGroups($logger, $options, $oauth, $dbh)
{
    $request_uri = $options['site'] . "api/taxo_groups";
    
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
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/taxo_groups_' . $i . '.json', $response);
        $logger->trace(print_r($data["data"][1], true));
        
        // Create table on first loop
        if ($i == 1) {
            $ddlNT = createTable($logger, $data["data"], $dbh, "taxo_groups");
        }
        // Insert rows
        $ddlNT = insertRows($logger, $data["data"], $dbh, "taxo_groups", $ddlNT);
        
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 10); // Limit to 10 request, to avoid bug infinite loop
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
 * @param void $dbh
 *            Database connection
 * @return void
 * @author Daniel Thonon
 *        
 */
function storeSpecies($logger, $options, $oauth, $dbh)
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
        $logger->debug("Demande de données " . $i . ", params:" . print_r($params, TRUE));
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
        
        // Create table on first loop
        if ($i == 1) {
            $ddlNT = createTable($logger, $data["data"], $dbh, "species");
        }
        // Insert rows
        $ddlNT = insertRows($logger, $data["data"], $dbh, "species", $ddlNT);
        
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
function storeObservations($logger, $options, $oauth, $dbh)
{
    $request_uri = $options['site'] . "api/observations";
    
    $params = array(
        'user_pw' => $options['user_pw'],
        'user_email' => $options['user_email']
    );
    // $params = array(
        // 'user_pw' => $options['user_pw'],
        // 'user_email' => $options['user_email'],
        // 'pagination_key' => $options['pagination_key']
    // );
	
    $i = 1;
    do {
        // Get data
        $oauth->enableDebug();
        $logger->debug("Demande de données " . $i . ", params:" . print_r($params, TRUE));
        try {
            $oauth->fetch($request_uri, $params, OAUTH_HTTP_METHOD_GET);
            // $logger->trace($oauth->getRequestHeader(OAUTH_HTTP_METHOD_GET, $request_uri));
            $logger->trace("Réception des données");
            $response = $oauth->getLastResponse();
            $logger->trace(print_r($oauth->getLastResponseInfo(), true));
            $respHead = $oauth->getLastResponseHeaders();
            $logger->trace($respHead);
            $pageNum = preg_match("/pagination_key: (.*)/", $respHead, $pageKey);
            $logger->debug("Page = " . $pageNum . ", key = |" . rtrim($pageKey[1]) . "|");
            $data = json_decode($response, true);
            $logger->debug("Reçu " . count($data["data"]["sightings"]) . " élements");
        } catch (\OAuthException $oauthException) {
            $response = $oauth->getLastResponse();
            $logger->error(print_r($oauth->getLastResponseInfo(), true));
            $json_error = json_decode($oauthException->lastResponse, true);
            $logger->error("Erreur de réception : " . var_export($json_error, true));
            $logger->error("Message d'erreur : " . $oauthException->getMessage());
            break;
        };
        if (count($data["data"]) == 0) {
            break;
        }
        file_put_contents(getenv('HOME') . '/' . $options['file_store'] . '/observations_' . $i . '.json', $response);
        
        $params = array(
            'user_pw' => $options['user_pw'],
            'user_email' => $options['user_email'],
            'pagination_key' => rtrim($pageKey[1])
        );
        $i += 1;
    } while ($i < 500); // Limit to 500 request, to avoid bug infinite loop
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
    "db_name:",         // Required: database name
    "db_user:",         // Required: database role
    "db_pw:",           // Required: database role password
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

// Open database and remote connection
try {
    $dbh = new PDO("pgsql:dbname=" . $options['db_name'] . 
                   ";user=" . $options['db_user'] . ";password=" . $options['db_pw'] . 
                   ";host=localhost;port=5432");
    
    // Get authorization from Biolovision
    try {
        $logger->trace("Getting oauth\n");
        $oauth = new OAuth($options['consumer_key'], $options['consumer_secret'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
        // $oauth->enableDebug();
    } catch (OAuthException $E) {
        $logger->error(print_r($E, true));
        die();
    }
} catch (PDOException $e) {
    $logger->error("Erreur !: " . $e->getMessage());
    die();
}

// Download and store entities
$logger->info("Téléchargement et stockage des 'entities'");
storeEntities($logger, $options, $oauth, $dbh);

// Download and store export organizations
$logger->info("Téléchargement et stockage des 'export_organizations'");
storeExport_Orgs($logger, $options, $oauth, $dbh);

// Download and store places
$logger->info("Téléchargement et stockage des 'places'");
storePlaces($logger, $options, $oauth, $dbh);

// Download and store admin units
$logger->info("Téléchargement et stockage des 'local admin units'");
storeAdminUnits($logger, $options, $oauth, $dbh);

// Download and store export families
$logger->info("Téléchargement et stockage des 'families'");
storeFamilies($logger, $options, $oauth, $dbh);

// Download and store export taxo_groups
$logger->info("Téléchargement et stockage des 'taxo_groups'");
storeTaxoGroups($logger, $options, $oauth, $dbh);

// Download and store export species
$logger->info("Téléchargement et stockage des 'species'");
storeSpecies($logger, $options, $oauth, $dbh);

// Download and store export observations
$logger->info("Téléchargement et stockage des 'observations'");
storeObservations($logger, $options, $oauth, $dbh);

$dbh = null;
?>
