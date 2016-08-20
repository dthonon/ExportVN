<?php
/**
 * Load exported json files to Postgresql database.
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
 * Provide access functions to the database.
 *
 */
class DbAccess
{
    /** Holds the Logger. */
    private $log;

    /** Holds the dh handle. */
    private $dbh;

    /** Holds the table name */
    private $table;

    /** Constructor stores parameters. */
    public function __construct($dbh, $table)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->dbh = $dbh;
        $this->table = $table;
    }

    /**
     * Finds the type of the value, based on its format
     *
     * @param string $val
     *            The value to be parsed
     * @return string The ddl type of $val
     * @author Daniel Thonon
     *
     */
    private function typeOfValue($val)
    {
        if (preg_match('/^-?\d+$/', $val) == 1) {
            return 'integer';
        } elseif (preg_match('/^-?\d+\.\d+$/', $val) == 1) {
            return 'double precision';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:.*$/', $val) == 1) {
            return 'timestamp with time zone';
        } else {
            return 'character varying';
        }
    }

    /**
     * Prepares the name & type part of the table creation DDL statement
     *
     * @param array $data
     *            Data to parse to find the names and types
     * @return string The DDL statement (name type...)
     * @author Daniel Thonon
     *
     */
    private function ddlNamesTypes($data)
    {
        // Analyze last element to define DDL types, as first could be special (i.e. integer instead of character)
        $this->log->trace(_('Analyse de l\'élement : ') . print_r($data[count($data) - 1], true));
        $ddl = array();
        reset($data);
        // Find the types
        foreach ($data[count($data) - 1] as $key => $value) {
            $ddl[$key] = $this->typeOfValue($value);
        }
        $this->log->trace(print_r($ddl, true));
        return $ddl;
    }

    /**
     * Insert the rows in a table, creating new colums as needed
     *
     * @param array $data
     *            Data to be iterated over to insert rows
     * @param array $ddlNT
     *            Array of (name, type) of the DDL columns already created
     * @return array $ddlNT
     *         Array of (name, type) of the DDL columns modified if needed
     * @author Daniel Thonon
     *
     */
    public function insertRows($data, $ddlNT)
    {
        $this->log->debug('Insertion des lignes dans ' . $this->table);
        $this->dbh->beginTransaction();
        // Loop over each data element
        $nbLines = 0;
        reset($data);
        foreach ($data as $key => $val) {
            // $this->log->trace(_('Analyse de l'élement pour insertion : ') . print_r($val, true));
            $rowKeys = '(';
            $rowVals = '(';
            reset($val);
            foreach ($val as $k => $v) {
                // Check if column already exists in the table
                if (! array_key_exists($k, $ddlNT)) {
                    $this->log->debug(_('Colonne absente de la table : ') . $k);
                    // Creation of the new column, outside of insertion transaction
                    $this->dbh->commit();
                    $ddlStmt = $k . ' ' . $this->typeOfValue($v) . ';';
                    $ddlStmt = 'ALTER TABLE ' . $this->table . ' ADD COLUMN ' . $ddlStmt;
                    $this->log->debug(_('Modification de la table ') . $ddlStmt);
                    $this->dbh->exec($ddlStmt);
                    $ddlNT[$k] = $this->typeOfValue($v); // Update column list
                    $this->dbh->beginTransaction();
                }

                $rowKeys .= $k . ','; // Add key to insert statement

                // Special case for empty insee column, forced to 0
                if ($k == 'insee' && $v == '') {
                    $v = '0';
                }
                // Special case for empty county column, forced to 0
                if ($k == 'county' && $v == '') {
                    $v = '0';
                }
                $rowVals .= '\'' . str_replace('\'', '\'\'', $v) . '\'' . ',';
            }
            $rowKeys = substr($rowKeys, 0, - 1) . ')';
            $rowVals = substr($rowVals, 0, - 1) . ')';
            $inData = 'INSERT INTO ' . $this->table . $rowKeys . ' VALUES ' . $rowVals;
            // $this->log->trace($inData);
            $nbLines += $this->dbh->exec($inData) or die(
                $this->log->fatal(
                    'Insertion incorrecte : ' .
                    $inData . '\n' . print_r($this->dbh->errorInfo(), true)
                )
            );
        }
        $this->dbh->commit();
        $this->log->debug($nbLines . _(' lignes insérées dans ') . $this->table);
        return $ddlNT;
    }

    /**
     * Drop table if exists
     *
     * @author Daniel Thonon
     *
     */
    public function dropTable()
    {
        // Delete if exists and create table
        $this->log->info(_('Suppression de la table ') . $this->table);
        $this->dbh->exec('DROP TABLE IF EXISTS ' . $this->table);
    }

    /**
     * Creates table with colums infered from $data, that must not exist before
     *
     * @param array $data
     *            Data to be iterated over to create columns
     * @return array $ddlNT
     *         Array of (name, type) of the DDL columns created
     * @author Daniel Thonon
     *
     */
    public function createTable($data)
    {
        // Prepare the DDL statement based on the analyzed types
        $ddlNT = $this->ddlNamesTypes($data);
        $ddlStmt = ' (';
        foreach ($ddlNT as $k => $v) {
            $ddlStmt .= $k . ' ' . $v . ',';
        }
        $ddlStmt = substr($ddlStmt, 0, - 1) . ');';
        $ddlStmt = 'CREATE TABLE ' . $this->table . $ddlStmt;
        $this->log->info(_('Création de la table ') . $ddlStmt);
        $this->dbh->exec($ddlStmt);
        return $ddlNT;
    }

}

/**
 * Parse data of generic (not observation) json structure and store in database.
 *
 */
class ParseData
{
    /** Holds the Logger. */
    private $log;

    /** Holds the dh handle. */
    private $dbh;

    /** Holds the table name. */
    private $table;

    /** Flag showing table has been dropped and created. */
    private $tableDropped;

    /** Holds the db handle name. */
    private $dba;

    /** List of columns, kept across files. */
    private $ddlNT = array();

    /** Constructor stores parameters. */
    public function __construct($dbh, $table)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->dbh = $dbh;
        $this->table = $table;
        $this->tableDropped = false;
        $this->dba = new DbAccess($this->dbh, $this->table);
    }

    /**
     * Parse and store in database.
     *
     * @param array $response
     *            Data to be iterated over to fill database
     * @return void
     * @author Daniel Thonon
     *
     */

    public function parse($response)
    {
        $this->log->info(_('Analyse des données json de ') . $this->table);

        $this->log->trace(_('Début de l\'analyse des ' . $this->table));
        $data = json_decode($response, true);

        // Drop and create table on first loop
        if (! $this->tableDropped) {
            $this->dba->dropTable();
            $this->ddlNT = $this->dba->createTable($data['data']);
            $this->tableDropped = true;
        }
        // Insert rows
        $this->ddlNT = $this->dba->insertRows($data['data'], $this->ddlNT);
    }
}

/**
 * Loops over files for a table, parse data and stor in database table.
 *
 */
class StoreFile
{
    /** Holds the Logger. */
    private $log;

    /** Holds the dh handle. */
    private $dbh;

    /** Holds the table name. */
    private $table;

    /** Holds the file storage directory. */
    private $fileStore;

    /** Holds the data parser. */
    private $parser;

    /** Holds the first ands last file number (limit for debug). */
    private $fileMin;
    private $fileMax;

    /** Constructor stores parameters. */
    public function __construct($dbh, $table, $fileStore, $fileMin = 1, $fileMax = 1000)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->dbh = $dbh;
        $this->table = $table;
        $this->fileStore = $fileStore;
        $this->parser = new ParseData($this->dbh, $this->table);
        $this->fileMin = $fileMin;
        $this->fileMax = $fileMax;

    }

    /**
     * Loop on json files and store in database.
     *
     * @return void
     * @author Daniel Thonon
     *
     */

    public function store()
    {
        $this->log->info(_('Chargement des fichiers json de ') . $this->table);

        // Loop on dowloaded files
        for ($fic = $this->fileMin; $fic < $this->fileMax; $fic++) {
            if (file_exists(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json')) {
                $this->log->info(_('Lecture du fichier ') . getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json');
                // Analyse du fichier
                $response = file_get_contents(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json');

                $this->parser->parse($response);
            }
        }
    }

}

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
 class ObsParser {

     /** Holds the Logger. */
     private $log;

     /** Holds the dh handle. */
     private $dbh;

     /** Holds the table name. */
     private $table;

     /** Flag showing table has been dropped and created. */
     private $tableDropped;

     /** Holds the db handle name. */
     private $dba;

     /** List of columns, kept across files. */
     private $ddlNT = array();

     /** Holds the logging trace level, to conditionaly execute tracing */
     private $tracing;

     /** Constructor stores parameters. */
     public function __construct($dbh, $table)
     {
         $this->log = Logger::getLogger(__CLASS__);
         $this->dbh = $dbh;
         $this->table = $table;
         $this->tableDropped = false;
         $this->dba = new DbAccess($this->dbh, $this->table);
         $this->tracing = $this->log->isTraceEnabled();
     }

     private function bDate($data, &$obs, $suffix)
     {
         if ($this->tracing) $this->log->trace('  ' . $suffix . 'date => ' . $data['@ISO8601']);
         $obs[$suffix . 'date'] = $data['@ISO8601'];
     }

     private function bSpecies($data, &$obs)
     {
         $this->log->trace('  species_id => ' . $data['@id']);
         $obs['id_species'] = $data['@id'];
         $this->log->trace('  species_name => ' . $data['name']);
         $obs['name_species'] = $data['name'];
         $this->log->trace('  species_latin_name => ' . $data['latin_name']);
         $obs['latin_species'] = $data['latin_name'];
     }

     private function bPlace($data, &$obs)
     {
         $this->log->trace('  id_place => ' . $data['@id']);
         $obs['id_place'] = $data['@id'];
         $this->log->trace('  place => ' . $data['name']);
         $obs['place'] = $data['name'];
         $this->log->trace('  municipality => ' . $data['municipality']);
         $obs['municipality'] = $data['municipality'];
         $this->log->trace('  insee => ' . $data['insee']);
         $obs['insee'] = $data['insee'];
         $this->log->trace('  county => ' . $data['county']);
         $obs['county'] = $data['county'];
         $this->log->trace('  country => ' . $data['country']);
         $obs['country'] = $data['country'];
         $this->log->trace('  altitude => ' . $data['altitude']);
         $obs['altitude'] = $data['altitude'];
         $this->log->trace('  coord_lat => ' . $data['coord_lat']);
         $obs['place_coord_lat'] = $data['coord_lat'];
         $this->log->trace('  coord_lon => ' . $data['coord_lon']);
         $obs['place_coord_lon'] = $data['coord_lon'];
         $this->log->trace('  loc_precision => ' . $data['loc_precision']);
         $obs['loc_precision'] = $data['loc_precision'];
         $this->log->trace('  place_type => ' . $data['place_type']);
         $obs['place_type'] = $data['place_type'];
     }

     private function bExtendedInfoMortality($data, &$obs, $suffix)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'cause':
                     $this->log->trace('    ' . $suffix . 'cause => ' . $value);
                     $obs[$suffix . 'cause'] = $value;
                     break;
                 case 'time_found':
                     $this->log->trace('    ' . $suffix . 'time_found => ' . $value);
                     $obs[$suffix . 'time_found'] = $value;
                     break;
                 case 'comment':
                     $this->log->trace('    ' . $suffix . 'comment => ' . $value);
                     $obs[$suffix . 'comment'] = $value;
                     break;
                 case 'electric_cause':
                     $this->log->trace('    ' . $suffix . 'electric_cause => ' . $value);
                     $obs[$suffix . 'electric_cause'] = $value;
                     break;
                 case 'trap':
                     $this->log->trace('    ' . $suffix . 'trap => ' . $value);
                     $obs[$suffix . 'trap'] = $value;
                     break;
                 case 'trap_circonstances':
                     $this->log->trace('    ' . $suffix . 'trap_circonstances => ' . $value);
                     $obs[$suffix . 'trap_circonstances'] = $value;
                     break;
                 case 'capture':
                     $this->log->trace('    ' . $suffix . 'capture => ' . $value);
                     $obs[$suffix . 'capture'] = $value;
                     break;
                 case 'electric_line_type':
                     $this->log->trace('    ' . $suffix . 'electric_line_type => ' . $value);
                     $obs[$suffix . 'electric_line_type'] = $value;
                     break;
                 case 'electric_line_configuration':
                     $this->log->trace('    ' . $suffix . 'electric_line_configuration => ' . $value);
                     $obs[$suffix . 'electric_line_configuration'] = $value;
                     break;
                 case 'electric_line_configuration_neutralised':
                     $this->log->trace('    ' . $suffix . 'electric_line_configuration_neutralised => ' . $value);
                     $obs[$suffix . 'electric_line_configuration_neutralised'] = $value;
                     break;
                 case 'electric_hta_pylon_id':
                     $this->log->trace('    ' . $suffix . 'electric_hta_pylon_id => ' . $value);
                     $obs[$suffix . 'electric_hta_pylon_id'] = $value;
                     break;
                 case 'fishing_collected':
                     $this->log->trace('    ' . $suffix . 'fishing_collected => ' . $value);
                     $obs[$suffix . 'fishing_collected'] = $value;
                     break;
                 case 'fishing_condition':
                     $this->log->trace('    ' . $suffix . 'fishing_condition => ' . $value);
                     $obs[$suffix . 'fishing_condition'] = $value;
                     break;
                 case 'fishing_mark':
                     $this->log->trace('    ' . $suffix . 'fishing_mark => ' . $value);
                     $obs[$suffix . 'fishing_mark'] = $value;
                     break;
                 case 'fishing_foreign_body':
                     $this->log->trace('    ' . $suffix . 'fishing_foreign_body => ' . $value);
                     $obs[$suffix . 'fishing_foreign_body'] = $value;
                     break;
                 case 'recipient':
                     $this->log->trace('    ' . $suffix . 'recipient => ' . $value);
                     $obs[$suffix . 'recipient'] = $value;
                     break;
                 case 'radio':
                     $this->log->trace('    ' . $suffix . 'radio => ' . $value);
                     $obs[$suffix . 'radio'] = $value;
                     break;
                 case 'collision_road_type':
                     $this->log->trace('    ' . $suffix . 'collision_road_type => ' . $value);
                     $obs[$suffix . 'collision_road_type'] = $value;
                     break;
                 case 'collision_track_id':
                     $this->log->trace('    ' . $suffix . 'collision_track_id => ' . $value);
                     $obs[$suffix . 'collision_track_id'] = $value;
                     break;
                 case 'collision_km_point':
                     $this->log->trace('    ' . $suffix . 'collision_km_point => ' . $value);
                     $obs[$suffix . 'collision_km_point'] = $value;
                     break;
                 case 'collision_near_element':
                     $this->log->trace('    ' . $suffix . 'collision_near_element => ' . $value);
                     $obs[$suffix . 'collision_near_element'] = $value;
                     break;
                 case 'predation':
                     $this->log->trace('    ' . $suffix . 'predation => ' . $value);
                     $obs[$suffix . 'predation'] = $value;
                     break;
                 case 'response':
                     $this->log->trace('    ' . $suffix . 'response => ' . $value);
                     $obs[$suffix . 'response'] = $value;
                     break;
                 case 'poison':
                     $this->log->trace('    ' . $suffix . 'poison => ' . $value);
                     $obs[$suffix . 'poison'] = $value;
                     break;
                 case 'pollution':
                     $this->log->trace('    ' . $suffix . 'pollution => ' . $value);
                     $obs[$suffix . 'pollution'] = $value;
                     break;
                 default:
                     $this->log->warn(_('    Elément extended_info_mortality inconnu : ') . $key);
             }
         }
     }

     private function bExtendedInfoBeardedVulture($data, &$obs, $suffix)
     {
         reset($data);
         $this->log->trace('    ' . $suffix . 'data => ' . print_r($data, true));
         return(print_r($data, true));
     }

     private function bExtendedInfoBeardedVultures($data, &$obs, $suffix)
     {
         reset($data);
         $beardedVulture = '';
         foreach ($data as $key => $value) {
             $beardedVulture = $beardedVulture . $this->bExtendedInfoBeardedVulture($value, $obs, $suffix . $key . '_');
         }
         $obs[$suffix . 'data'] = $beardedVulture;
     }

     private function bExtendedInfoColony($data, &$obs, $suffix)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'nests':
                     $this->log->trace('    ' . $suffix . 'nests => ' . $value);
                     $obs[$suffix . 'nests'] = $value;
                     break;
                 case 'occupied_nests':
                     $this->log->trace('    ' . $suffix . 'occupied_nests => ' . $value);
                     $obs[$suffix . 'occupied_nests'] = $value;
                     break;
                 case 'nests_is_min':
                     $this->log->trace('    ' . $suffix . 'nests_is_min => ' . $value);
                     $obs[$suffix . 'nests_is_min'] = $value;
                     break;
                 case 'nests_is_max':
                     $this->log->trace('    ' . $suffix . 'nests_is_max => ' . $value);
                     $obs[$suffix . 'nests_is_max'] = $value;
                     break;
                 case 'couples':
                     $this->log->trace('    ' . $suffix . 'couples => ' . $value);
                     $obs[$suffix . 'couples'] = $value;
                     break;
                 default:
                     $this->log->warn(_('    Elément extended_info_colony inconnu : ') . $key);
             }
         }
     }

     private function bExtendedInfoColonyExtended($data, &$obs, $suffix)
     {
         reset($data);
         $colonyExtended = '(';
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'couples':
                     $this->log->trace('    ' . $suffix . 'couples => ' . $value);
                     $colonyExtended = $colonyExtended . ',couples=' . $value;
                     break;
                 case 'nests':
                     $this->log->trace('    ' . $suffix . 'nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests' . $value;
                     break;
                 case 'nests_is_min':
                     $this->log->trace('    ' . $suffix . 'nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests_is_min' . $value;
                     break;
                 case 'nests_is_max':
                     $this->log->trace('    ' . $suffix . 'nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests_is_max' . $value;
                     break;
                 case 'occupied_nests':
                     $this->log->trace('    ' . $suffix . 'occupied_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',occupied_nests' . $value;
                     break;
                 case 'nb_natural_nests':
                     $this->log->trace('    ' . $suffix . 'nb_natural_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests=' . $value;
                     break;
                 case 'nb_natural_nests_is_min':
                     $this->log->trace('    ' . $suffix . 'nb_natural_nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests_is_min' . $value;
                     break;
                 case 'nb_natural_nests_is_max':
                     $this->log->trace('    ' . $suffix . 'nb_natural_nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests_is_max' . $value;
                     break;
                 case 'nb_natural_occup_nests':
                     $this->log->trace('    ' . $suffix . 'nb_natural_occup_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests=' . $value;
                     break;
                 case 'nb_natural_other_species_nests':
                     $this->log->trace('    ' . $suffix . 'nb_natural_other_species_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_other_species_nests=' . $value;
                     break;
                 case 'nb_natural_destructed_nests':
                     $this->log->trace('    ' . $suffix . 'nb_natural_destructed_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_destructed_nests=' . $value;
                     break;
                 case 'nb_artificial_nests':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests=' . $value;
                     break;
                 case 'nb_artificial_nests_is_min':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests_is_min=' . $value;
                     break;
                 case 'nb_artificial_nests_is_max':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests_is_max=' . $value;
                     break;
                 case 'nb_artificial_occup_nests':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_occup_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_occup_nests=' . $value;
                     break;
                 case 'nb_artificial_other_species_nests':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_other_species_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_other_species_nests=' . $value;
                     break;
                 case 'nb_artificial_destructed_nests':
                     $this->log->trace('    ' . $suffix . 'nb_artificial_destructed_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_destructed_nests=' . $value;
                     break;
                 case 'nb_construction_nests':
                     $this->log->trace('    ' . $suffix . 'nb_construction_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_construction_nests=' . $value;
                     break;
                 default:
                     $this->log->warn(_('    Elément extended_info_colony_extended inconnu : ') . $key);
             }
         }
         return $colonyExtended . ')';
     }

     private function bExtendedInfos($data, &$obs, $suffix)
     {
         reset($data);
         $colonyExtended = $suffix . ':';
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'mortality':
                     $this->log->trace('    ' . $suffix . 'mortality =>');
                     $this->bExtendedInfoMortality($value, $obs, $suffix . $key . '_');
                     break;
                 case 'gypaetus_barbatus':
                     $this->log->trace('    ' . $suffix . 'gypaetus_barbatus =>');
                     $this->bExtendedInfoBeardedVultures($value, $obs, $suffix . $key . '_');
                     break;
                 case 'colony':
                     $this->log->trace('    ' . $suffix . 'colony =>');
                     $this->bExtendedInfoColony($value, $obs, $suffix . $key . '_');
                     break;
                 case 'colony_extended':
                     $this->log->trace('    ' . $suffix . 'colony_extended => ');
                     foreach ($data as $key => $value) {
                         $colonyExtended = $colonyExtended . $this->bExtendedInfoColonyExtended($value, $obs, '');
                     }
                     $obs[$suffix . 'colony_extended_list'] = $colonyExtended;
                     break;
                 default:
                     $this->log->warn(_('    Elément extended_infos inconnu : ') . $key);
             }
         }
     }

     private function bSousDetail($data, $obs, $suffix)
     {
         reset($data);
         $sousDetail = $suffix . ':';
         foreach ($data as $key => $value) {
             switch ($key) {
                 case '@id':
                     $this->log->trace('    ' . $suffix . 'cause => ' . $value);
                     $sousDetail = 'id=' . $value;
                     break;
                 case '#text':
                     $this->log->trace('    ' . $suffix . 'cause => ' . $value);
                     $sousDetail = 'text=' .  $value;
                     break;
             }
         }
         return $sousDetail;
     }

     private function bDetail($data, &$obs, $suffix)
     {
         reset($data);
         $detail = '(';
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'count':
                     $this->log->trace('  ' . $suffix . 'count => ' . $value);
                     $detail = $detail. ',count=' . $value;
                     break;
                 case 'age':
                     $this->log->trace('  ' . $suffix . 'age => ' . $value['@id']);
                     $detail = $detail. ',age=' .  $value['@id'];
                     break;
                 case 'sex':
                     $this->log->trace('  ' . $suffix . 'sex => ' . $value['@id']);
                     $detail = $detail. ',sex=' .  $value['@id'];
                     break;
                 case 'condition':
                     $this->log->trace('  ' . $suffix . 'condition => ' . $value['@id']);
                     $detail = $detail. ',condition=' .  $value['@id'];
                     break;
                 case 'distance':
                     $detail = $detail. ',distance=' . $this->bSousDetail($value, $obs, $suffix . $key . '_');
                     break;
                 default:
                     $this->log->warn(_('  Elément detail inconnu : ') . $key . ' => ' . print_r($value, true));
             }
         }
         return $detail . ')';
     }

     private function bDetails($data, &$obs, $suffix)
     {
         reset($data);
         $details = '';
         foreach ($data as $key => $value) {
             $details = $details . $this->bDetail($value, $obs, '');
         }
         $obs[$suffix . 'list'] = $details;
     }

     private function bObserver($data, &$obs)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case '@id':
                     $this->log->trace('  id_observer => ' . $data['@id']);
                     $obs['id_observer'] = $data['@id'];
                     break;
                 case 'name':
                     $this->log->trace('  name => ' . $data['name']);
                     $obs['name'] = $data['name'];
                     break;
                 case 'id_sighting':
                     $this->log->trace('  id_sighting => ' . $data['id_sighting']);
                     $obs['id_sighting'] = $data['id_sighting'];
                     break;
                 case 'id_form':
                     $this->log->trace('  id_form => ' . $data['@id']);
                     $obs['id_form'] = $data['@id'];
                     break;
                 case 'coord_lat':
                     $this->log->trace('  coord_lat => ' . $data['coord_lat']);
                     $obs['observer_coord_lat'] = $data['coord_lat'];
                     break;
                 case 'coord_lon':
                     $this->log->trace('  coord_lon => ' . $data['coord_lon']);
                     $obs['observer_coord_lon'] = $data['coord_lon'];
                     break;
                 case 'altitude':
                     $this->log->trace('  altitude => ' . $data['altitude']);
                     $obs['altitude'] = $data['altitude'];
                     break;
                 case 'precision':
                     $this->log->trace('  precision => ' . $data['precision']);
                     $obs['precision'] = $data['precision'];
                     break;
                 case 'atlas_grid_name':
                     $this->log->trace('  atlas_grid_name => ' . $data['atlas_grid_name']);
                     $obs['atlas_grid_name'] = $data['atlas_grid_name'];
                     break;
                 case 'atlas_code':
                     $this->bSousDetail($value, $obs, 'atlas_code_');
                     break;
                 case 'behaviours':
                     $this->bSousDetail($value[0], $obs, 'behaviours_');
                     break;
                 case 'count':
                     $this->log->trace('  count => ' . $data['count']);
                     $obs['count'] = $data['count'];
                     break;
                 case 'count_string':
                     $this->log->trace('  count_string => ' . $data['count_string']);
                     $obs['count_string'] = $data['count_string'];
                     break;
                 case 'estimation_code':
                     $this->log->trace('  estimation_code => ' . $data['estimation_code']);
                     $obs['estimation_code'] = $data['estimation_code'];
                     break;
                 case 'flight_number':
                     $this->log->trace('  flight_number => ' . $data['flight_number']);
                     $obs['flight_number'] = $data['flight_number'];
                     break;
                 case 'has_death':
                     $this->log->trace('  has_death => ' . $data['has_death']);
                     $obs['has_death'] = $data['has_death'];
                     break;
                 case 'project_code':
                     $this->log->trace('  project_code => ' . $data['project_code']);
                     $obs['project_code'] = $data['project_code'];
                     break;
                 case 'admin_hidden':
                     $this->log->trace('  admin_hidden => ' . $data['admin_hidden']);
                     $obs['admin_hidden'] = $data['admin_hidden'];
                     break;
                 case 'admin_hidden_type':
                     $this->log->trace('  admin_hidden_type => ' . $data['admin_hidden_type']);
                     $obs['admin_hidden_type'] = $data['admin_hidden_type'];
                     break;
                 case 'hidden':
                     $this->log->trace('  hidden => ' . $data['hidden']);
                     $obs['hidden'] = $data['hidden'];
                     break;
                 case 'comment':
                     $this->log->trace('  comment => ' . $data['comment']);
                     $obs['comment'] = $data['comment'];
                     break;
                 case 'hidden_comment':
                     $this->log->trace('  hidden_comment => ' . $data['hidden_comment']);
                     $obs['hidden_comment'] = $data['hidden_comment'];
                     break;
                 case 'entity':
                     $this->log->trace('  entity => ' . $data['entity']);
                     $obs['entity'] = $data['entity'];
                     break;
                 case 'entity_fullname':
                     $this->log->trace('  entity_fullname => ' . $data['entity_fullname']);
                     $obs['entity_fullname'] = $data['entity_fullname'];
                     break;
                 case 'project':
                     $this->log->trace('  project => ' . $data['project']);
                     $obs['project'] = $data['project'];
                     break;
                 case 'insert_date':
                     $this->bDate($value, $obs, 'insert_');
                     break;
                 case 'update_date':
                     $this->bDate($value, $obs, 'update_');
                     break;
                 case 'export_date':
                     $this->bDate($value, $obs, 'export_');
                     break;
                 case 'timing':
                     $this->bDate($value, $obs, 'timing_');
                     break;
                 case 'extended_info':
                     $this->bExtendedInfos($value, $obs, 'extended_info_');
                     break;
                 case 'details':
                     $this->bDetails($value, $obs, 'details_');
                     break;
                 case 'medias':
                     $this->log->trace('  medias non implemented');
                     break;
                 case '@uid':
                     $this->log->trace('  @uid non implemented');
                     break;
                 case 'id_universal':
                     $this->log->trace('  id_universal non implemented');
                     break;
                 default:
                     $this->log->warn(_('  Elément observer inconnu : ') . $key . ' => ' . print_r($value, true));
             }
         }
     }

     private function bObservers($data, &$obs)
     {
         reset($data);
         foreach ($data as $key => $value) {
             $this->bObserver($value, $obs);
         }
     }

     private function bSighting($data, &$obs)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'date':
                     $this->log->trace('Elément: ' . $key);
                     $this->bDate($value, $obs, '');
                     break;
                 case 'species':
                     $this->log->trace('Elément: ' . $key);
                     $this->bSpecies($value, $obs);
                     break;
                 case 'place':
                     $this->log->trace('Elément: ' . $key);
                     $this->bPlace($value, $obs);
                     break;
                 case 'observers':
                     $this->log->trace('Elément: ' . $key);
                     $this->bObservers($value, $obs);
                     break;
                 default:
                     $this->log->warn(_('Elément sighting inconnu : ') . $key);
             }
         }
         $this->log->trace(print_r($obs, true));
     }

     public function bSightings($data, &$obsDropped, $ddlNT)
     {
         $rowMin = 0; // starting record for debug
         $rowMax = 1000000000; // ending record for debug
         $nbRow = 0;

         $obsArray = array(); // Store observations per row
         reset($data);

         $this->log->trace(_('Analyse d\'une observation'));
         foreach ($data as $key => $value) {
             $nbRow = $nbRow + 1;
             if ($nbRow < $rowMin) {
                 continue;
             }
             $this->log->trace(_('Elément sightings numéro : ') . $nbRow);
             // $this->log->debug(_('Elements : ') . print_r(array_keys($value, TRUE)));
             $obs = array();
             $this->bSighting($value, $obs);
             $obsArray[] = $obs;
             if ($nbRow > $rowMax) {
                 break;
             }
         }

         // Create table on first pass and insert data, if sightings not empty
         if ($nbRow > 0) {
             if ($obsDropped) {
                 $ddlNT = $this->dba->createTable($obsArray);
                 $obsDropped = false;
             }
             // Insert data
             $ddlNT = $this->dba->insertRows($obsArray, $ddlNT);
         }

         return($ddlNT);
     }

     public function bForms($data, &$obsDropped, $ddlNT)
     {
         $nbRow = 0;
         reset($data);

         $this->log->trace(_('Analyse d\'un formulaire'));
         foreach ($data as $key => $value) {
             $nbRow = $nbRow + 1;
             $this->log->trace(_('Elément forms numéro : ') . $nbRow);
             foreach ($value as $keyS => $valueS) {
                 switch ($keyS) {
                     case 'sightings':
                         $ddlNT = $this->bSightings($valueS, $obsDropped, $ddlNT);
                         break;
                     default:
                         $this->log->trace(_('Element forms non traité : ') . $keyS);
                 }
             }
         }

         return($ddlNT);
     }

     public function parse($response)
     {
         $this->log->info(_('Analyse des données json de ') . $this->table);

         // Drop table on first loop. It will be created after parsing.
         if (! $this->tableDropped) {
             $this->dba->dropTable();
             $this->tableDropped = true;
         }

         $this->log->trace(_('Début de l\'analyse des ' . $this->table));
         $data = json_decode($response, true);

         $sightings = (is_array($data) && (array_key_exists('sightings', $data['data']))) ?
                         count($data['data']['sightings']) :
                         0;
         $forms = (is_array($data) && (array_key_exists('forms', $data['data']))) ?
                      count($data['data']['forms']) :
                      0;

         // Empty file => exit
         if ($sightings + $forms == 0) {
             $this->log->warn(_('Fichier de données vide'));
         } else {
             $this->log->debug(_('Chargement de ') . $sightings . ' élements sightings');
             $this->log->debug(_('Chargement de ') . $forms . ' élements forms');
             reset($data);
             foreach ($data['data'] as $key => $value) {
                 $this->log->trace(_('Analyse de l\'élement : ') . $key);
                 switch ($key) {
                     case 'sightings':
                         $this->ddlNT = $this->bSightings($value, $this->tableDropped, $this->ddlNT);
                         break;
                     case 'forms':
                         $this->ddlNT = $this->bForms($value, $this->tableDropped, $this->ddlNT);
                         break;
                     default:
                         $this->log->warn(_('Element racine inconnu: ') . $key);
                 }
             }
             $this->log->info(_('Fin de l\'analyse d\'un fichier d\'observations'));
         }

     }
 }

/**
 * Loop on json files and call parser on json structure to store in database
 *
 * @param array $dbh
 *            database handle
 * @return void
 * @author Daniel Thonon
 *
 */

function observations($dbh)
{
    global $logger;
    global $options;

    $fileMin = 1;    // min file for debug
    $fileMax = 3000; // max file for debug

    // Create specific parser for observation data
    $parser = new ObsParser($dbh, 'observations');

    $logger->info(_('Chargement des fichiers json d\'observations'));

    // Loop on dowloaded files
    for ($fic = $fileMin; $fic < $fileMax; $fic++) {
        if (file_exists(getenv('HOME') . '/' . $options['file_store'] . '/observations_' . $fic . '.json')) {
            $logger->info(
                _('Lecture du fichier ') . getenv('HOME') . '/' .
                $options['file_store'] . '/observations_' . $fic . '.json'
            );
            // Analyse du fichier
            $response = file_get_contents(
                getenv('HOME') . '/' .
                $options['file_store'] . '/observations_' . $fic . '.json'
            );

            $parser->parse($response);

        }
    }
}

///////////////////////// Main ////////////////////////////////////
// Larger memory to handle observations
ini_set('memory_limit', '1024M');

// Define command line options
$shortOpts = ''; // No short form options

$longOpts = array(
    'db_host:',         // Required: database host
    'db_port:',         // Required: database ip port
    'db_name:',         // Required: database name
    'db_schema:',         // Required: database name
    'db_user:',         // Required: database role
    'db_pw:',           // Required: database role password
    'file_store:',      // Required: directory where downloaded json files are stored. Relative to $HOME
    'logging::'         // Optional: debugging messages
)
;

$options = getopt($shortOpts, $longOpts);

// Create logger and set level. Should not be changed after, as used in class constructor
$logger = Logger::getRootLogger();
$logger->setLevel(LoggerLevel::toLevel($options['logging']));

$logger->info(_('Début de l\'import'));
//$logger->trace(var_export($options, true));

// Open database connection
try {
    $dbh = new PDO(
        'pgsql:dbname=' . $options['db_name']
        . ';host=' . $options['db_host']
        . ';port=' . $options['db_port'],
        $options['db_user'],
        $options['db_pw'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

} catch (PDOException $e) {
    $logger->fatal(_('Erreur de connexion à la base: ') . $e->getMessage());
    die();
}
// Set schema in default path
try {
    $dbh->exec('SET search_path TO ' . $options['db_schema']);
}catch (PDOException $e) {
    $logger->fatal(_('Erreur de positionnement du schéma ') . $options['db_schema'] . $dbh->errorMsg());
    die();
}

// Store entities in database
$entities = new StoreFile($dbh, 'entities', $options['file_store']);
$entities->store();
unset($entities);

// Store export_organizations in database
$export_organizations = new StoreFile($dbh, 'export_organizations', $options['file_store']);
$export_organizations->store();
unset($export_organizations);

// Store families in database
$families = new StoreFile($dbh, 'families', $options['file_store']);
$families->store();
unset($families);

// Store grids in database
$grids = new StoreFile($dbh, 'grids', $options['file_store']);
$grids->store();
unset($grids);

// Store local_admin_units in database
$local_admin_units = new StoreFile($dbh, 'local_admin_units', $options['file_store']);
$local_admin_units->store();
unset($local_admin_units);

// Store observations in database
observations($dbh);

// Store places in database
$places = new StoreFile($dbh, 'places', $options['file_store']);
$places->store();
unset($places);

// Store species in database
$species = new StoreFile($dbh, 'species', $options['file_store']);
$species->store();
unset($species);

// Store taxo_groups in database
$taxo_groups = new StoreFile($dbh, 'taxo_groups', $options['file_store']);
$taxo_groups->store();
unset($taxo_groups);

// Store territorial_units in database
$territorial_units = new StoreFile($dbh, 'territorial_units', $options['file_store']);
$territorial_units->store();
unset($territorial_units);

// Close database connection
unset($dbh);
