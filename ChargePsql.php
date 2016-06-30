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

// Create logger and set level
Logger::configure('config.xml');
$logger = Logger::getRootLogger();

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
 * Prepares the name,type part of the table creation DDL statement
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
                // Creation of the new column, outside of insertion transaction
                $dbh->commit();
                $ddlStmt = $k . " " . typeOfValue($v) . ";";
                $ddlStmt = "ALTER TABLE " . $table . " ADD COLUMN " . $ddlStmt;
                $logger->debug("Modification de la table " . $ddlStmt);
                $dbh->exec($ddlStmt);
                $ddlNT[$k] = typeOfValue($v); // Update column list
                $dbh->beginTransaction();
            }
            
            $rowKeys .= $k . ","; // Add key to insert statement
            
            // Special case for empty insee column, forced to 0
            if ($k == "insee" && $v == "") {
                $v = "0";
            }                
            // Special case for empty county column, forced to 0
            if ($k == "county" && $v == "") {
                $v = "0";
            }                
            $rowVals .= "'" . str_replace("'", "''", $v) . "'" . ",";
        }
        $rowKeys = substr($rowKeys, 0, - 1) . ")";
        $rowVals = substr($rowVals, 0, - 1) . ")";
        $inData = "INSERT INTO " . $table . $rowKeys . " VALUES " . $rowVals;
        // $logger->trace($inData);
        $nbLines += $dbh->exec($inData) or die($logger->fatal("Insertion incorrecte : " . 
                                                              $inData . "\n" . print_r($dbh->errorInfo(), true)));
    }
    $dbh->commit();
    $logger->debug($nbLines . " lignes insérées dans " . $table);
    return $ddlNT;
}

/**
 * Drop table if exists
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
function dropTable($logger, $dbh, $table)
{
    // Delete if exists and create table
    $logger->info("Suppression de la table " . $table);
    $dbh->exec("DROP TABLE IF EXISTS " . $table);
}

/**
 * Creates table, that must not exist before
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
    // Prepare the DDL statement based on the analyzed types
    $ddlNT = ddlNamesTypes($logger, $data);
    $ddlStmt = " (";
    foreach ($ddlNT as $k => $v) {
        $ddlStmt .= $k . " " . $v . ",";
    }
    $ddlStmt = substr($ddlStmt, 0, - 1) . ");";
    $ddlStmt = "CREATE TABLE " . $table . $ddlStmt;
    $logger->info("Création de la table " . $ddlStmt);
    $dbh->exec($ddlStmt);
    return $ddlNT;
}

/**
 * Store places
 *
 */

/**
 * Recursive descent parser functions for observations, named by element
 *  - Depending on element, either warning for unknown element, or ignored
 *
 * @param array $data
 *            Json element to parse
 * @param array &$obs
 *            Observation being filled by parser, using key as column name in general
 * @param array $suffix
 *            for sub-elements, suffix to prepend to column name
 * @return void
 * @author Daniel Thonon
 *        
 */
function bDate($data, &$obs, $suffix)
{
    global $logger;
    $logger->trace("  " . $suffix . "date => " . $data["@ISO8601"]);
    $obs[$suffix . "date"] = $data["@ISO8601"];
}

function bSpecies($data, &$obs)
{
    global $logger;
    $logger->trace("  species_id => " . $data["@id"]);
    $obs["id_species"] = $data["@id"];
    $logger->trace("  species_name => " . $data["name"]);
    $obs["name_species"] = $data["name"];
    $logger->trace("  species_latin_name => " . $data["latin_name"]);
    $obs["latin_species"] = $data["latin_name"];
}

function bPlace($data, &$obs)
{
    global $logger;
    $logger->trace("  id_place => " . $data["@id"]);
    $obs["id_place"] = $data["@id"];
    $logger->trace("  place => " . $data["name"]);
    $obs["place"] = $data["name"];
    $logger->trace("  municipality => " . $data["municipality"]);
    $obs["municipality"] = $data["municipality"];
    $logger->trace("  insee => " . $data["insee"]);
    $obs["insee"] = $data["insee"];
    $logger->trace("  county => " . $data["county"]);
    $obs["county"] = $data["county"];
    $logger->trace("  country => " . $data["country"]);
    $obs["country"] = $data["country"];
    $logger->trace("  altitude => " . $data["altitude"]);
    $obs["altitude"] = $data["altitude"];
    $logger->trace("  coord_lat => " . $data["coord_lat"]);
    $obs["place_coord_lat"] = $data["coord_lat"];
    $logger->trace("  coord_lon => " . $data["coord_lon"]);
    $obs["place_coord_lon"] = $data["coord_lon"];
    $logger->trace("  loc_precision => " . $data["loc_precision"]);
    $obs["loc_precision"] = $data["loc_precision"];
    $logger->trace("  place_type => " . $data["place_type"]);
    $obs["place_type"] = $data["place_type"];
}

function bExtendedInfoMortality($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    foreach ($data as $key => $value) {
        switch ($key) {
            case "cause":
                $logger->trace("    " . $suffix . "cause => " . $value);
                $obs[$suffix . "cause"] = $value;
                break;
            case "time_found":
                $logger->trace("    " . $suffix . "time_found => " . $value);
                $obs[$suffix . "time_found"] = $value;
                break;
            case "comment":
                $logger->trace("    " . $suffix . "comment => " . $value);
                $obs[$suffix . "comment"] = $value;
                break;
            case "electric_cause":
                $logger->trace("    " . $suffix . "electric_cause => " . $value);
                $obs[$suffix . "electric_cause"] = $value;
                break;
            case "trap":
                $logger->trace("    " . $suffix . "trap => " . $value);
                $obs[$suffix . "trap"] = $value;
                break;
             case "trap_circonstances":
                $logger->trace("    " . $suffix . "trap_circonstances => " . $value);
                $obs[$suffix . "trap_circonstances"] = $value;
                break;
            case "capture":
                $logger->trace("    " . $suffix . "capture => " . $value);
                $obs[$suffix . "capture"] = $value;
                break;
             case "electric_line_type":
                $logger->trace("    " . $suffix . "electric_line_type => " . $value);
                $obs[$suffix . "electric_line_type"] = $value;
                break;
             case "electric_line_configuration":
                $logger->trace("    " . $suffix . "electric_line_configuration => " . $value);
                $obs[$suffix . "electric_line_configuration"] = $value;
                break;
             case "electric_line_configuration_neutralised":
                $logger->trace("    " . $suffix . "electric_line_configuration_neutralised => " . $value);
                $obs[$suffix . "electric_line_configuration_neutralised"] = $value;
                break;
             case "electric_hta_pylon_id":
                $logger->trace("    " . $suffix . "electric_hta_pylon_id => " . $value);
                $obs[$suffix . "electric_hta_pylon_id"] = $value;
                break;
              case "fishing_collected":
                $logger->trace("    " . $suffix . "fishing_collected => " . $value);
                $obs[$suffix . "fishing_collected"] = $value;
                break;
              case "fishing_condition":
                $logger->trace("    " . $suffix . "fishing_condition => " . $value);
                $obs[$suffix . "fishing_condition"] = $value;
                break;
              case "fishing_mark":
                $logger->trace("    " . $suffix . "fishing_mark => " . $value);
                $obs[$suffix . "fishing_mark"] = $value;
                break;
              case "fishing_foreign_body":
                $logger->trace("    " . $suffix . "fishing_foreign_body => " . $value);
                $obs[$suffix . "fishing_foreign_body"] = $value;
                break;
             case "recipient":
                $logger->trace("    " . $suffix . "recipient => " . $value);
                $obs[$suffix . "recipient"] = $value;
                break;
             case "radio":
                $logger->trace("    " . $suffix . "radio => " . $value);
                $obs[$suffix . "radio"] = $value;
                break;
             case "collision_road_type":
                $logger->trace("    " . $suffix . "collision_road_type => " . $value);
                $obs[$suffix . "collision_road_type"] = $value;
                break;
             case "collision_track_id":
                $logger->trace("    " . $suffix . "collision_track_id => " . $value);
                $obs[$suffix . "collision_track_id"] = $value;
                break;
             case "collision_km_point":
                $logger->trace("    " . $suffix . "collision_km_point => " . $value);
                $obs[$suffix . "collision_km_point"] = $value;
                break;
             case "collision_near_element":
                $logger->trace("    " . $suffix . "collision_near_element => " . $value);
                $obs[$suffix . "collision_near_element"] = $value;
                break;
             case "predation":
                $logger->trace("    " . $suffix . "predation => " . $value);
                $obs[$suffix . "predation"] = $value;
                break;
             case "response":
                $logger->trace("    " . $suffix . "response => " . $value);
                $obs[$suffix . "response"] = $value;
                break;
             case "poison":
                $logger->trace("    " . $suffix . "poison => " . $value);
                $obs[$suffix . "poison"] = $value;
                break;
          default:
                $logger->warn("    Elément extended_info_mortality inconnu: " . $key);
        }
    }
}

function bExtendedInfoBeardedVulture($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $logger->trace("    " . $suffix . "data => " . print_r($data, TRUE));
    return(print_r($data, TRUE));
}

function bExtendedInfoBeardedVultures($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $bearded_vulture = "";
    foreach ($data as $key => $value) {
        $bearded_vulture = $bearded_vulture . bExtendedInfoBeardedVulture($value, $obs, $suffix . $key . "_");
    }    
    $obs[$suffix . "data"] = $bearded_vulture;
}

function bExtendedInfoColony($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    foreach ($data as $key => $value) {
        switch ($key) {
             case "nests":
                $logger->trace("    " . $suffix . "nests => " . $value);
                $obs[$suffix . "nests"] = $value;
                break;
             case "occupied_nests":
                $logger->trace("    " . $suffix . "occupied_nests => " . $value);
                $obs[$suffix . "occupied_nests"] = $value;
                break;
             case "nests_is_min":
                $logger->trace("    " . $suffix . "nests_is_min => " . $value);
                $obs[$suffix . "nests_is_min"] = $value;
                break;
             case "nests_is_max":
                $logger->trace("    " . $suffix . "nests_is_max => " . $value);
                $obs[$suffix . "nests_is_max"] = $value;
                break;
             case "couples":
                $logger->trace("    " . $suffix . "couples => " . $value);
                $obs[$suffix . "couples"] = $value;
                break;
          default:
                $logger->warn("    Elément extended_info_colony inconnu: " . $key);
        }
    }
}

function bExtendedInfoColonyExtended($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $colony_extended = "(";
    foreach ($data as $key => $value) {
        switch ($key) {
             case "couples":
                $logger->trace("    " . $suffix . "couples => " . $value);
                $colony_extended = $colony_extended . ",couples=" . $value;
                break;
             case "nests":
                $logger->trace("    " . $suffix . "nests => " . $value);
                $colony_extended = $colony_extended . ",nests" . $value;
                break;
             case "nests_is_min":
                $logger->trace("    " . $suffix . "nests_is_min => " . $value);
                $colony_extended = $colony_extended . ",nests_is_min" . $value;
                break;
             case "nests_is_max":
                $logger->trace("    " . $suffix . "nests_is_max => " . $value);
                $colony_extended = $colony_extended . ",nests_is_max" . $value;
                break;
             case "occupied_nests":
                $logger->trace("    " . $suffix . "occupied_nests => " . $value);
                $colony_extended = $colony_extended . ",occupied_nests" . $value;
                break;
             case "nb_natural_nests":
                $logger->trace("    " . $suffix . "nb_natural_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_nests=" . $value;
                break;
             case "nb_natural_nests_is_min":
                $logger->trace("    " . $suffix . "nb_natural_nests_is_min => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_nests_is_min" . $value;
                break;
             case "nb_natural_nests_is_max":
                $logger->trace("    " . $suffix . "nb_natural_nests_is_max => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_nests_is_max" . $value;
                break;
             case "nb_natural_occup_nests":
                $logger->trace("    " . $suffix . "nb_natural_occup_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_nests=" . $value;
                break;
             case "nb_natural_other_species_nests":
                $logger->trace("    " . $suffix . "nb_natural_other_species_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_other_species_nests=" . $value;
                break;
             case "nb_natural_destructed_nests":
                $logger->trace("    " . $suffix . "nb_natural_destructed_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_natural_destructed_nests=" . $value;
                break;
             case "nb_artificial_nests":
                $logger->trace("    " . $suffix . "nb_artificial_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_nests=" . $value;
                break;
             case "nb_artificial_nests_is_min":
                $logger->trace("    " . $suffix . "nb_artificial_nests_is_min => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_nests_is_min=" . $value;
                break;
             case "nb_artificial_nests_is_max":
                $logger->trace("    " . $suffix . "nb_artificial_nests_is_max => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_nests_is_max=" . $value;
                break;
             case "nb_artificial_occup_nests":
                $logger->trace("    " . $suffix . "nb_artificial_occup_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_occup_nests=" . $value;
                break;
             case "nb_artificial_other_species_nests":
                $logger->trace("    " . $suffix . "nb_artificial_other_species_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_other_species_nests=" . $value;
                break;
             case "nb_artificial_destructed_nests":
                $logger->trace("    " . $suffix . "nb_artificial_destructed_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_artificial_destructed_nests=" . $value;
                break;
             case "nb_construction_nests":
                $logger->trace("    " . $suffix . "nb_construction_nests => " . $value);
                $colony_extended = $colony_extended . ",nb_construction_nests=" . $value;
                break;
          default:
                $logger->warn("    Elément extended_info_colony_extended inconnu: " . $key);
        }
    }
    return $colony_extended . ")";
}

function bExtendedInfos($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $colony_extended = $suffix . ":";
    foreach ($data as $key => $value) {
        switch ($key) {
            case "mortality":
                $logger->trace("    " . $suffix . "mortality =>");
                bExtendedInfoMortality($value, $obs, $suffix . $key . "_");
                break;
            case "gypaetus_barbatus":
                $logger->trace("    " . $suffix . "gypaetus_barbatus =>");
                bExtendedInfoBeardedVultures($value, $obs, $suffix . $key . "_");
                break;
            case "colony":
                $logger->trace("    " . $suffix . "colony =>");
                bExtendedInfoColony($value, $obs, $suffix . $key . "_");
                break;
             case "colony_extended":
                $logger->trace("    " . $suffix . "colony_extended => ");
                foreach ($data as $key => $value) {
                    $colony_extended = $colony_extended . bExtendedInfoColonyExtended($value, $obs, "");
                }
                $obs[$suffix . "colony_extended_list"] = $colony_extended;
                break;
            default:
                $logger->warn("    Elément extended_infos inconnu: " . $key);
        }
        
    }
}

function bSousDetail($data, $obs, $suffix)
{
    global $logger;
    reset($data);
    $sousDetail = $suffix . ":";
    foreach ($data as $key => $value) {
        switch ($key) {
            case "@id":
               $logger->trace("    " . $suffix . "cause => " . $value);
                $sousDetail = "id=" . $value;
                break;
            case "#text":
               $logger->trace("    " . $suffix . "cause => " . $value);
               $sousDetail = "text=" .  $value;
               break;
        }
    }
    return $sousDetail;
}

function bDetail($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $detail = "(";
    foreach ($data as $key => $value) {
        switch ($key) {
            case "count":
                $logger->trace("  " . $suffix . "count => " . $value);
                $detail = $detail. ",count=" . $value;
                break;
            case "age":
                $logger->trace("  " . $suffix . "age => " . $value["@id"]);
                $detail = $detail. ",age=" .  $value["@id"];
                break;
            case "sex":
                $logger->trace("  " . $suffix . "sex => " . $value["@id"]);
                $detail = $detail. ",sex=" .  $value["@id"];
                break;
            case "condition":
                $logger->trace("  " . $suffix . "condition => " . $value["@id"]);
                $detail = $detail. ",condition=" .  $value["@id"];
                break;
            case "distance":
                $detail = $detail. ",distance=" . bSousDetail($value, $obs, $suffix . $key . "_");
                break;
            default:
                $logger->warn("  Elément detail inconnu: " . $key . " => " . print_r($value, TRUE));
        }
    }
    return $detail . ")";
}

function bDetails($data, &$obs, $suffix)
{
    global $logger;
    reset($data);
    $details = "";
    foreach ($data as $key => $value) {
        $details = $details . bDetail($value, $obs, "");
    }
    $obs[$suffix . "list"] = $details;
}

function bObserver($data, &$obs)
{
    global $logger;
    reset($data);
    foreach ($data as $key => $value) {
        switch ($key) {
            case "@id":
                $logger->trace("  id_observer => " . $data["@id"]);
                $obs["id_observer"] = $data["@id"];
                break;
            case "name":
                $logger->trace("  name => " . $data["name"]);
                $obs["name"] = $data["name"];
                break;
            case "id_sighting":
                $logger->trace("  id_sighting => " . $data["id_sighting"]);
                $obs["id_sighting"] = $data["id_sighting"];
                break;
            case "id_form":
                $logger->trace("  id_form => " . $data["@id"]);
                $obs["id_form"] = $data["@id"];
                break;
            case "coord_lat":
                $logger->trace("  coord_lat => " . $data["coord_lat"]);
                $obs["observer_coord_lat"] = $data["coord_lat"];
                break;
            case "coord_lon":
                $logger->trace("  coord_lon => " . $data["coord_lon"]);
                $obs["observer_coord_lon"] = $data["coord_lon"];
                break;
            case "altitude":
                $logger->trace("  altitude => " . $data["altitude"]);
                $obs["altitude"] = $data["altitude"];
                break;
            case "precision":
                $logger->trace("  precision => " . $data["precision"]);
                $obs["precision"] = $data["precision"];
                break;
            case "atlas_grid_name":
                $logger->trace("  atlas_grid_name => " . $data["atlas_grid_name"]);
                $obs["atlas_grid_name"] = $data["atlas_grid_name"];
                break;
            case "atlas_code":
                bSousDetail($value, $obs, "atlas_code_");
                break;
            case "behaviours":
                bSousDetail($value[0], $obs, "behaviours_");
                break;
            case "count":
                $logger->trace("  count => " . $data["count"]);
                $obs["count"] = $data["count"];
                break;
            case "count_string":
                $logger->trace("  count_string => " . $data["count_string"]);
                $obs["count_string"] = $data["count_string"];
                break;
            case "estimation_code":
                $logger->trace("  estimation_code => " . $data["estimation_code"]);
                $obs["estimation_code"] = $data["estimation_code"];
                break;
            case "flight_number":
                $logger->trace("  flight_number => " . $data["flight_number"]);
                $obs["flight_number"] = $data["flight_number"];
                break;
            case "has_death":
                $logger->trace("  has_death => " . $data["has_death"]);
                $obs["has_death"] = $data["has_death"];
                break;
           case "project_code":
                $logger->trace("  project_code => " . $data["project_code"]);
                $obs["project_code"] = $data["project_code"];
                break;
            case "admin_hidden":
                $logger->trace("  admin_hidden => " . $data["admin_hidden"]);
                $obs["admin_hidden"] = $data["admin_hidden"];
                break;
            case "admin_hidden_type":
                $logger->trace("  admin_hidden_type => " . $data["admin_hidden_type"]);
                $obs["admin_hidden_type"] = $data["admin_hidden_type"];
                break;
            case "hidden":
                $logger->trace("  hidden => " . $data["hidden"]);
                $obs["hidden"] = $data["hidden"];
                break;
            case "comment":
                $logger->trace("  comment => " . $data["comment"]);
                $obs["comment"] = $data["comment"];
                break;
            case "hidden_comment":
                $logger->trace("  hidden_comment => " . $data["hidden_comment"]);
                $obs["hidden_comment"] = $data["hidden_comment"];
                break;
            case "entity":
                $logger->trace("  entity => " . $data["entity"]);
                $obs["entity"] = $data["entity"];
                break;
            case "entity_fullname":
                $logger->trace("  entity_fullname => " . $data["entity_fullname"]);
                $obs["entity_fullname"] = $data["entity_fullname"];
                break;
            case "project":
                $logger->trace("  project => " . $data["project"]);
                $obs["project"] = $data["project"];
                break;
            case "insert_date":
                bDate($value, $obs, "insert_");
                break;
            case "update_date":
                bDate($value, $obs, "update_");
                break;
            case "export_date":
                bDate($value, $obs, "export_");
                break;
            case "timing":
                bDate($value, $obs, "timing_");
                break;
            case "extended_info":
                bExtendedInfos($value, $obs, "extended_info_");
                break;
            case "details":
                bDetails($value, $obs, "details_");
                break;
            case "medias":
                $logger->trace("  medias non implemented");
                break;
            case "@uid":
                $logger->trace("  @uid non implemented");
                break;
            case "id_universal":
                $logger->trace("  id_universal non implemented");
                break;
            default:
                $logger->warn("  Elément observer inconnu: " . $key . " => " . print_r($value, TRUE));
        }
    }
}

function bObservers($data, &$obs)
{
    global $logger;
    reset($data);
    foreach ($data as $key => $value) {
        bObserver($value, $obs);
    }
}

function bSighting($data, &$obs)
{
    global $logger;
    reset($data);
    foreach ($data as $key => $value) {
        switch ($key) {
            case "date":
                $logger->trace("Elément: " . $key);
                bDate($value, $obs, "");
                break;
            case "species":
                $logger->trace("Elément: " . $key);
                bSpecies($value, $obs);
                break;
            case "place":
                $logger->trace("Elément: " . $key);
                bPlace($value, $obs);
                break;
            case "observers":
                $logger->trace("Elément: " . $key);
                bObservers($value, $obs);
                break;
            default:
                $logger->warn("Elément sighting inconnu: " . $key);
        }
    }
    $logger->trace(print_r($obs, TRUE));
    
}

function bSightings($data, $dbh, &$obs_dropped, $ddlNT)
{
    global $logger;
    $row_min = 0; # starting record for debug
    $row_max = 1000000000; # ending record for debug
    $nbRow = 0;
    
    $obs_array = array(); // Store observations per row
    reset($data);
    
    $logger->trace("Analyse d'une observation");
    foreach ($data as $key => $value) {
        $nbRow = $nbRow + 1;
        if ($nbRow < $row_min) {
            continue;
        }
        $logger->trace("Elément sightings numéro: " . $nbRow);
        // $logger->debug("Elements: " . print_r(array_keys($value, TRUE)));
        $obs = array();
        bSighting($value, $obs);
        $obs_array[] = $obs;
        if ($nbRow > $row_max) {
            break;
        }
    }

    // Create table on first pass and insert data, if sightings not empty
	if ($nbRow > 0) {
		if ($obs_dropped) {
			$ddlNT = createTable($logger, $obs_array, $dbh, "observations");
			$obs_dropped = FALSE;
		}
		// Insert data
		$ddlNT = insertRows($logger, $obs_array, $dbh, "observations", $ddlNT);		
	}
    
   return($ddlNT);
}

function bForms($data, $dbh, &$obs_dropped, $ddlNT)
{
    global $logger;
    $nbRow = 0;
    reset($data);
    
    $logger->trace("Analyse d'un formulaire");
	foreach ($data as $key => $value) {
        $nbRow = $nbRow + 1;
        $logger->trace("Elément forms numéro: " . $nbRow);
        foreach ($value as $keyS => $valueS) {
            switch ($keyS) {
                case "sightings":
                    $ddlNT = bSightings($valueS, $dbh, $obs_dropped, $ddlNT);
                    break;
                default:
                    $logger->trace("Element forms non traité : " . $keyS);
            }
        }
      }
    
    return($ddlNT);
}

function observations($dbh)
{
    global $logger;
	global $options;
    
    $ddlNT = array(); // List of columns, kept across files

    $file_min = 1;    # min file for debug
    $file_max = 1000; # max file for debug

    // First, drop observations table
    dropTable($logger, $dbh, "observations");
    $obs_dropped = TRUE;

    // Loop on dowloaded files
    for ($fic = $file_min; $fic < $file_max; $fic++) {
        if (file_exists(getenv('HOME') . '/' . $options['file_store'] . "/observations_" . $fic . ".json")) {
            $logger->info("Lecture du fichier " . getenv('HOME') . '/' . $options['file_store'] . "/observations_" . $fic . ".json");
            // Analyse du fichier
            $response = file_get_contents(getenv('HOME') . '/' . $options['file_store'] . "/observations_" . $fic . ".json");
        
            $logger->trace("Début de l'analyse des observations");
            $data = json_decode($response, true);
            
            $sightings = (is_array($data) && (array_key_exists("sightings", $data["data"]))) ? count($data["data"]["sightings"]) : 0;
            $forms = (is_array($data) && (array_key_exists("forms", $data["data"]))) ? count($data["data"]["forms"]) : 0;
            
            // Empty file => exit
            if ($sightings + $forms == 0) {
                $logger->warn("Fichier de données vide");
            } else {  
                $logger->info("Chargement de " . $sightings . " élements sightings");
                $logger->info("Chargement de " . $forms . " élements forms");
                reset($data);
                foreach ($data["data"] as $key => $value) {
                    $logger->trace("Analyse de l'élement : " . $key);
                    switch ($key) {
                        case "sightings":
                            $ddlNT = bSightings($value, $dbh, $obs_dropped, $ddlNT);
                             break;
                        case "forms":
                            $ddlNT = bForms($value, $dbh, $obs_dropped, $ddlNT);
                            break;
                        default:
                            $logger->warn("Element racine inconnu: " . $key);
                        }
                }
                $logger->info("Fin de l'analyse d'un fichier d'observations");
            }

        }
    }
}
// ///////////////////////// Main ////////////////////////////////////
// Larger memory to handle observations
ini_set('memory_limit', '1024M');

// Define command line options
$shortOpts = ""; // No short form options

$longOpts = array(
    "db_name:",         // Required: database name
    "db_user:",         // Required: database role
    "db_pw:",           // Required: database role password
	"file_store:",      // Required: directory where downloaded json files are stored. Relative to $HOME
    "logging::"         // Optional: debugging messages
) 
;

$options = getopt($shortOpts, $longOpts);

// Create logger and set level
Logger::configure('config.xml');
$logger = Logger::getRootLogger();
$logger->setLevel(LoggerLevel::toLevel($options['logging']));

$logger->info("Début de l'import");
//$logger->trace(var_export($options, true));

// Open database
try {
    $dbh = new PDO("pgsql:dbname=" . $options['db_name'] . ";user=" . $options['db_user'] . ";password=" . $options['db_pw'] . ";host=localhost");
} catch (PDOException $e) {
    $logger->error("Erreur !: " . $e->getMessage());
    die();
}

// Store observations in database
observations($dbh);

$dbh = null;
?>
