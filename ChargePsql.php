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
 * Totalizer of db insertions per file analyzed
 *
 */
class DbInsertCounter
{
    /** Holds the Logger. */
    private $log;

    /** Holds the insert count */
    private $inserts;

    /** Constructor stores parameters. */
    public function __construct()
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->inserts = 0;
    }

    /**
     * Increment insertion counter
     *
     * @param integer $nbLines
     *            Number of inserted rows
     * @author Daniel Thonon
     *
     */
    public function insertRows($nbLines)
    {
        $this->inserts = $this->inserts + $nbLines;
    }

    /**
     * Return insertion counter
     *
     * @return integer
     *            Number of inserted rows
     * @author Daniel Thonon
     *
     */
    public function NbInserted()
    {
        return $this->inserts;
    }

}

$DbInsertions = array();

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
     * @param string $name
     *            The column name, to handle special cases
     * @param string $val
     *            The value to be parsed
     * @return string
     *            The ddl type of $val
     * @author Daniel Thonon
     *
     */
    private function typeOfValue($name, $val)
    {
        if (preg_match('/^extended.*$/', $name) == 1) {
            // All extended colums are character
            return 'character varying';
        } else {
            // Analyze value for type
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
        // Analyze several elements to define DDL types, as first could be special (i.e. integer instead of character)
        $ddl = array();
        for ($i = 0; $i < 3; $i++) {
            // Find the types of this line
            $l = rand(0, count($data) - 1);
            $this->log->trace(_('Analyse de l\'élement : ') . print_r($data[$l], true));
            foreach ($data[$l] as $key => $value) {
                $ddl[$key] = $this->typeOfValue($key, $value);
            }
            $this->log->trace(print_r($ddl, true));
        }
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
    public function insertRows($data, $ddlNT, $insertCounter)
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
                    $ddlStmt = $k . ' ' . $this->typeOfValue($k, $v) . ';';
                    $ddlStmt = 'ALTER TABLE ' . $this->table . ' ADD COLUMN ' . $ddlStmt;
                    $this->log->debug(_('Modification de la table ') . $ddlStmt);
                    $this->dbh->exec($ddlStmt);
                    $ddlNT[$k] = $this->typeOfValue($k, $v); // Update column list
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
        $insertCounter->insertRows($nbLines);
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
        $this->log->info(_('Création de la table ') . $this->table);
        $this->log->debug(_('DDL: ') . $ddlStmt);
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

    /** Count parsing passes: table dropped and created on first passs. */
    private $passNumber;

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
        $this->passNumber = 0;
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

    public function parse($response, $insertCounter)
    {
        $this->log->debug(_('Analyse des données json de ') . $this->table);

        $this->log->trace(_('Début de l\'analyse des ' . $this->table));
        $data = json_decode($response, true);

        if (count($data['data']) > 0) {
            // Non empty file : drop and create table on first loop
            if ($this->passNumber == 0) {
                $this->dba->dropTable();
                $this->ddlNT = $this->dba->createTable($data['data']);
                $this->passNumber++;
            }
            // Insert rows
            $this->ddlNT = $this->dba->insertRows($data['data'], $this->ddlNT, $insertCounter);
        } else {
            // File with no data
            $this->log->info(_('Fichier ignoré : pas de données'));
        }
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
    public function __construct($dbh, $table, $parse, $fileStore, $fileMin = 1, $fileMax = 1000)
    {
        $this->log = Logger::getLogger(__CLASS__);
        $this->dbh = $dbh;
        $this->table = $table;
        $this->fileStore = $fileStore;
        $this->parser = new $parse($this->dbh, $this->table);
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
        global $DbInsertions;

        $this->log->debug(_('Chargement des fichiers json de ') . $this->table);

        // Loop on dowloaded files
        for ($fic = $this->fileMin; $fic < $this->fileMax; $fic++) {
            if (file_exists(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json')) {
                $this->log->info(_('Lecture du fichier ') . getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json');
                // Read stored json file
                $response = file_get_contents(getenv('HOME') . '/' . $this->fileStore . '/' . $this->table . '_' . $fic . '.json');

                // Correct missing comment value (incorrect character)
                $response = str_replace('"comment": ,',
                                        '"comment": "!!!Commentaire supprimé car caractère incorrect",',
                                         $response, $pbCar);
                if ($pbCar > 0) {
                    $this->log->warn(_('Commentaire incorrect supprimés: ') . $pbCar);
                }

                // Create insertion counter (for debug)
                $DbInsertions[$this->table . '_' . $fic . '.json'] = new DbInsertCounter();

                // Parse JSON file
                $this->parser->parse($response, $DbInsertions[$this->table . '_' . $fic . '.json']);
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

     /** Count parsing passes: table dropped and created on first passs. */
     private $passNumber;

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
         $this->passNumber = 0;
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
         if ($this->tracing) $this->log->trace('  species_id => ' . $data['@id']);
         $obs['id_species'] = $data['@id'];
        //  if ($this->tracing) $this->log->trace('  species_name => ' . $data['name']);
        //  $obs['name_species'] = $data['name'];
        //  if ($this->tracing) $this->log->trace('  species_latin_name => ' . $data['latin_name']);
        //  $obs['latin_species'] = $data['latin_name'];
     }

     private function bPlace($data, &$obs)
     {
         if ($this->tracing) $this->log->trace('  id_place => ' . $data['@id']);
         $obs['id_place'] = $data['@id'];
        //  if ($this->tracing) $this->log->trace('  place => ' . $data['name']);
        //  $obs['place'] = $data['name'];
        //  if ($this->tracing) $this->log->trace('  municipality => ' . $data['municipality']);
        //  $obs['municipality'] = $data['municipality'];
        //  if ($this->tracing) $this->log->trace('  insee => ' . $data['insee']);
        //  $obs['insee'] = $data['insee'];
        //  if ($this->tracing) $this->log->trace('  county => ' . $data['county']);
        //  $obs['county'] = $data['county'];
        //  if ($this->tracing) $this->log->trace('  country => ' . $data['country']);
        //  $obs['country'] = $data['country'];
        //  if ($this->tracing) $this->log->trace('  altitude => ' . $data['altitude']);
        //  $obs['altitude'] = $data['altitude'];
        //  if ($this->tracing) $this->log->trace('  coord_lat => ' . $data['coord_lat']);
        //  $obs['place_coord_lat'] = $data['coord_lat'];
        //  if ($this->tracing) $this->log->trace('  coord_lon => ' . $data['coord_lon']);
        //  $obs['place_coord_lon'] = $data['coord_lon'];
        //  if ($this->tracing) $this->log->trace('  loc_precision => ' . $data['loc_precision']);
        //  $obs['loc_precision'] = $data['loc_precision'];
        //  if ($this->tracing) $this->log->trace('  place_type => ' . $data['place_type']);
        //  $obs['place_type'] = $data['place_type'];
     }

     private function bExtendedInfoMortality($data, &$obs, $suffix)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case 'cause':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'cause => ' . $value);
                     $obs[$suffix . 'cause'] = $value;
                     break;
                 case 'time_found':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'time_found => ' . $value);
                     $obs[$suffix . 'time_found'] = $value;
                     break;
                 case 'comment':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'comment => ' . $value);
                     $search = array("\t", "\n", "\r", "\0", "\x0B");
                     $value = str_replace($search, ' ', $value);
                     $search = array('"');
                     $value = str_replace($search, "'", $value);
                     $obs[$suffix . 'comment'] = $value;
                     break;
                 case 'electric_cause':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'electric_cause => ' . $value);
                     $obs[$suffix . 'electric_cause'] = $value;
                     break;
                 case 'trap':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'trap => ' . $value);
                     $obs[$suffix . 'trap'] = $value;
                     break;
                 case 'trap_circonstances':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'trap_circonstances => ' . $value);
                     $obs[$suffix . 'trap_circonstances'] = $value;
                     break;
                 case 'capture':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'capture => ' . $value);
                     $obs[$suffix . 'capture'] = $value;
                     break;
                 case 'electric_line_type':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'electric_line_type => ' . $value);
                     $obs[$suffix . 'electric_line_type'] = $value;
                     break;
                 case 'electric_line_configuration':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'electric_line_configuration => ' . $value);
                     $obs[$suffix . 'electric_line_configuration'] = $value;
                     break;
                 case 'electric_line_configuration_neutralised':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'electric_line_configuration_neutralised => ' . $value);
                     $obs[$suffix . 'electric_line_configuration_neutralised'] = $value;
                     break;
                 case 'electric_hta_pylon_id':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'electric_hta_pylon_id => ' . $value);
                     $obs[$suffix . 'electric_hta_pylon_id'] = $value;
                     break;
                 case 'fishing_collected':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'fishing_collected => ' . $value);
                     $obs[$suffix . 'fishing_collected'] = $value;
                     break;
                 case 'fishing_condition':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'fishing_condition => ' . $value);
                     $obs[$suffix . 'fishing_condition'] = $value;
                     break;
                 case 'fishing_mark':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'fishing_mark => ' . $value);
                     $obs[$suffix . 'fishing_mark'] = $value;
                     break;
                 case 'fishing_foreign_body':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'fishing_foreign_body => ' . $value);
                     $obs[$suffix . 'fishing_foreign_body'] = $value;
                     break;
                 case 'recipient':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'recipient => ' . $value);
                     $obs[$suffix . 'recipient'] = $value;
                     break;
                 case 'radio':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'radio => ' . $value);
                     $obs[$suffix . 'radio'] = $value;
                     break;
                 case 'collision_road_type':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'collision_road_type => ' . $value);
                     $obs[$suffix . 'collision_road_type'] = $value;
                     break;
                 case 'collision_track_id':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'collision_track_id => ' . $value);
                     $obs[$suffix . 'collision_track_id'] = $value;
                     break;
                 case 'collision_km_point':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'collision_km_point => ' . $value);
                     $obs[$suffix . 'collision_km_point'] = $value;
                     break;
                 case 'collision_near_element':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'collision_near_element => ' . $value);
                     $obs[$suffix . 'collision_near_element'] = $value;
                     break;
                 case 'predation':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'predation => ' . $value);
                     $obs[$suffix . 'predation'] = $value;
                     break;
                 case 'response':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'response => ' . $value);
                     $obs[$suffix . 'response'] = $value;
                     break;
                 case 'poison':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'poison => ' . $value);
                     $obs[$suffix . 'poison'] = $value;
                     break;
                 case 'pollution':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'pollution => ' . $value);
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
         if ($this->tracing) $this->log->trace('    ' . $suffix . 'data => ' . print_r($data, true));
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
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests => ' . $value);
                     $obs[$suffix . 'nests'] = $value;
                     break;
                 case 'occupied_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'occupied_nests => ' . $value);
                     $obs[$suffix . 'occupied_nests'] = $value;
                     break;
                 case 'nests_is_min':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests_is_min => ' . $value);
                     $obs[$suffix . 'nests_is_min'] = $value;
                     break;
                 case 'nests_is_max':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests_is_max => ' . $value);
                     $obs[$suffix . 'nests_is_max'] = $value;
                     break;
                 case 'couples':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'couples => ' . $value);
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
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'couples => ' . $value);
                     $colonyExtended = $colonyExtended . ',couples=' . $value;
                     break;
                 case 'nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests' . $value;
                     break;
                 case 'nests_is_min':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests_is_min' . $value;
                     break;
                 case 'nests_is_max':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nests_is_max' . $value;
                     break;
                 case 'occupied_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'occupied_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',occupied_nests' . $value;
                     break;
                 case 'nb_natural_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests=' . $value;
                     break;
                 case 'nb_natural_nests_is_min':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests_is_min' . $value;
                     break;
                 case 'nb_natural_nests_is_max':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests_is_max' . $value;
                     break;
                 case 'nb_natural_occup_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_occup_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_nests=' . $value;
                     break;
                 case 'nb_natural_other_species_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_other_species_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_other_species_nests=' . $value;
                     break;
                 case 'nb_natural_destructed_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_natural_destructed_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_natural_destructed_nests=' . $value;
                     break;
                 case 'nb_artificial_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests=' . $value;
                     break;
                 case 'nb_artificial_nests_is_min':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_nests_is_min => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests_is_min=' . $value;
                     break;
                 case 'nb_artificial_nests_is_max':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_nests_is_max => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_nests_is_max=' . $value;
                     break;
                 case 'nb_artificial_occup_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_occup_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_occup_nests=' . $value;
                     break;
                 case 'nb_artificial_other_species_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_other_species_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_other_species_nests=' . $value;
                     break;
                 case 'nb_artificial_destructed_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_artificial_destructed_nests => ' . $value);
                     $colonyExtended = $colonyExtended . ',nb_artificial_destructed_nests=' . $value;
                     break;
                 case 'nb_construction_nests':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'nb_construction_nests => ' . $value);
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
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'mortality =>');
                     $this->bExtendedInfoMortality($value, $obs, $suffix . $key . '_');
                     break;
                 case 'gypaetus_barbatus':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'gypaetus_barbatus =>');
                     $this->bExtendedInfoBeardedVultures($value, $obs, $suffix . $key . '_');
                     break;
                 case 'colony':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'colony =>');
                     $this->bExtendedInfoColony($value, $obs, $suffix . $key . '_');
                     break;
                 case 'colony_extended':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'colony_extended => ');
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
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'cause => ' . $value);
                     $sousDetail = 'id=' . $value;
                     break;
                 case '#text':
                     if ($this->tracing) $this->log->trace('    ' . $suffix . 'cause => ' . $value);
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
                     if ($this->tracing) $this->log->trace('  ' . $suffix . 'count => ' . $value);
                     $detail = $detail . ',count=' . $value;
                     break;
                 case 'age':
                     if ($this->tracing) $this->log->trace('  ' . $suffix . 'age => ' . $value['@id']);
                     $detail = $detail . ',age=' .  $value['@id'];
                     break;
                 case 'sex':
                     if ($this->tracing) $this->log->trace('  ' . $suffix . 'sex => ' . $value['@id']);
                     $detail = $detail . ',sex=' .  $value['@id'];
                     break;
                 case 'condition':
                     if ($this->tracing) $this->log->trace('  ' . $suffix . 'condition => ' . $value['@id']);
                     $detail = $detail . ',condition=' .  $value['@id'];
                     break;
                 case 'distance':
                     if ($this->tracing) $this->log->trace('  ' . $suffix . 'distance => ' . $value['@id']);
                     $detail = $detail . ',distance=' .  $value['@id'];
                     // $detail = $detail . ',distance=' . $this->bSousDetail($value, $obs, $suffix . $key . '_');
                     break;
                case 'section':
                    if ($this->tracing) $this->log->trace('  ' . $suffix . 'section => ' . $value['@id']);
                    $detail = $detail . ',section=' .  $value['@id'];
                     // $detail = $detail . ',section=' . $this->bSousDetail($value, $obs, $suffix . $key . '_');
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

     private function bBehaviours($data, &$obs, $suffix)
     {
         reset($data);
         $details = '';
         foreach ($data as $key => $value) {
             if ($this->tracing) $this->log->trace('  ' . $suffix . 'text => ' . $value['#text']);
             $details = $details . $value['#text'] . " ; ";
         }
         $obs[$suffix . 'list'] = $details;
     }

     private function bObserver($data, &$obs)
     {
         reset($data);
         foreach ($data as $key => $value) {
             switch ($key) {
                 case '@id':
                     if ($this->tracing) $this->log->trace('  id_observer => ' . $data['@id']);
                     $obs['id_observer'] = $data['@id'];
                     break;
                 case 'name':
                     if ($this->tracing) $this->log->trace('  name => ' . $data['name']);
                     $obs['observer_name'] = $data['name'];
                     break;
                 case 'id_sighting':
                     if ($this->tracing) $this->log->trace('  id_sighting => ' . $data['id_sighting']);
                     $obs['id_sighting'] = $data['id_sighting'];
                     break;
                 case 'id_form':
                     if ($this->tracing) $this->log->trace('  id_form => ' . $data['@id']);
                     $obs['id_form'] = $data['@id'];
                     break;
                 case 'coord_lat':
                     if ($this->tracing) $this->log->trace('  coord_lat => ' . $data['coord_lat']);
                     $obs['observer_coord_lat'] = $data['coord_lat'];
                     break;
                 case 'coord_lon':
                     if ($this->tracing) $this->log->trace('  coord_lon => ' . $data['coord_lon']);
                     $obs['observer_coord_lon'] = $data['coord_lon'];
                     break;
                 case 'altitude':
                     if ($this->tracing) $this->log->trace('  altitude => ' . $data['altitude']);
                     $obs['altitude'] = $data['altitude'];
                     break;
                 case 'precision':
                     if ($this->tracing) $this->log->trace('  precision => ' . $data['precision']);
                     $obs['precision'] = $data['precision'];
                     break;
                 case 'atlas_grid_name':
                     if ($this->tracing) $this->log->trace('  atlas_grid_name => ' . $data['atlas_grid_name']);
                     $obs['atlas_grid_name'] = $data['atlas_grid_name'];
                     break;
                 case 'atlas_code':
                     if ($this->tracing) $this->log->trace('  atlas_code => ' . $data['atlas_code']['#text']);
-                    $obs['atlas_code'] = $data['atlas_code']['#text'];
                     break;
                 case 'behaviours':
                     $this->bBehaviours($data['behaviours'], $obs, 'behaviours_');
                     break;
                 case 'count':
                     if ($this->tracing) $this->log->trace('  count => ' . $data['count']);
                     $obs['count'] = $data['count'];
                     break;
                 case 'count_string':
                     if ($this->tracing) $this->log->trace('  count_string => ' . $data['count_string']);
                     $obs['count_string'] = $data['count_string'];
                     break;
                 case 'estimation_code':
                     if ($this->tracing) $this->log->trace('  estimation_code => ' . $data['estimation_code']);
                     $obs['estimation_code'] = $data['estimation_code'];
                     break;
                 case 'flight_number':
                     if ($this->tracing) $this->log->trace('  flight_number => ' . $data['flight_number']);
                     $obs['flight_number'] = $data['flight_number'];
                     break;
                 case 'has_death':
                     if ($this->tracing) $this->log->trace('  has_death => ' . $data['has_death']);
                     $obs['has_death'] = $data['has_death'];
                     break;
                 case 'project_code':
                     if ($this->tracing) $this->log->trace('  project_code => ' . $data['project_code']);
                     $obs['project_code'] = $data['project_code'];
                     break;
                     case 'project_name':
                         if ($this->tracing) $this->log->trace('  project_name => ' . $data['project_name']);
                         $obs['project_name'] = $data['project_name'];
                         break;
                 case 'admin_hidden':
                     if ($this->tracing) $this->log->trace('  admin_hidden => ' . $data['admin_hidden']);
                     $obs['admin_hidden'] = $data['admin_hidden'];
                     break;
                 case 'admin_hidden_type':
                     if ($this->tracing) $this->log->trace('  admin_hidden_type => ' . $data['admin_hidden_type']);
                     $obs['admin_hidden_type'] = $data['admin_hidden_type'];
                     break;
                 case 'hidden':
                     if ($this->tracing) $this->log->trace('  hidden => ' . $data['hidden']);
                     $obs['hidden'] = $data['hidden'];
                     break;
                 case 'comment':
                     if ($this->tracing) $this->log->trace('  comment => ' . $data['comment']);
                     $search = array("\t", "\n", "\r", "\0", "\x0B");
                     $value = str_replace($search, ' ', $data['comment']);
                     $search = array('"');
                     $value = str_replace($search, "'", $value);
                     $obs['comment'] = $value;
                     break;
                 case 'hidden_comment':
                     if ($this->tracing) $this->log->trace('  hidden_comment => ' . $data['hidden_comment']);
                     $search = array("\t", "\n", "\r", "\0", "\x0B");
                     $value = str_replace($search, ' ', $data['hidden_comment']);
                     $search = array('"');
                     $value = str_replace($search, "'", $value);
                     $obs['hidden_comment'] = $value;
                     break;
                 case 'entity':
                     if ($this->tracing) $this->log->trace('  entity => ' . $data['entity']);
                     $obs['entity'] = $data['entity'];
                     break;
                 case 'entity_fullname':
                     if ($this->tracing) $this->log->trace('  entity_fullname => ' . $data['entity_fullname']);
                     // Not stored as entity is enough
                     //  $obs['entity_fullname'] = $data['entity_fullname'];
                     break;
                 case 'project':
                     if ($this->tracing) $this->log->trace('  project => ' . $data['project']);
                     $obs['project'] = $data['project'];
                     break;
                 case 'committees_validation':
                     if ($this->tracing) $this->log->trace('  committees_validation => ' . $data['committees_validation']);
                     $obs['committees_validation'] = json_encode($data['committees_validation']);
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
                     if ($this->tracing) $this->log->trace('  medias non implemented');
                     break;
                 case '@uid':
                     if ($this->tracing) $this->log->trace('  @uid non implemented');
                     break;
                 case 'id_universal':
                     if ($this->tracing) $this->log->trace('  id_universal non implemented');
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
                     if ($this->tracing) $this->log->trace('Elément: ' . $key);
                     $this->bDate($value, $obs, '');
                     break;
                 case 'species':
                     if ($this->tracing) $this->log->trace('Elément: ' . $key);
                     $this->bSpecies($value, $obs);
                     break;
                 case 'place':
                     if ($this->tracing) $this->log->trace('Elément: ' . $key);
                     $this->bPlace($value, $obs);
                     break;
                 case 'observers':
                     if ($this->tracing) $this->log->trace('Elément: ' . $key);
                     $this->bObservers($value, $obs);
                     break;
                 default:
                     $this->log->warn(_('Elément sighting inconnu : ') . $key);
             }
         }
         if ($this->tracing) $this->log->trace(print_r($obs, true));
     }

     public function bSightings($data, $ddlNT, $insertCounter, &$obsInit)
     {
         $rowMin = 0; // starting record for debug
         $rowMax = 1000000000; // ending record for debug
         $nbRow = 0;

         $obsArray = array(); // Store observations per row

         reset($data);
         if ($this->tracing) $this->log->trace(_('Analyse d\'une observation'));
         foreach ($data as $key => $value) {
             $nbRow = $nbRow + 1;
             if ($nbRow < $rowMin) {
                 continue;
             }
             if ($this->tracing) $this->log->trace(_('Elément sightings numéro : ') . $nbRow);
             // $this->log->debug(_('Elements : ') . print_r(array_keys($value, TRUE)));
             $obs = array_merge(array(), $obsInit);
             $this->bSighting($value, $obs);
             $obsArray[] = $obs;
             if ($nbRow > $rowMax) {
                 break;
             }
         }

         // Create table on first pass and insert data, if sightings not empty
         if ($nbRow > 0) {
             if ($this->passNumber == 0) {
                 $ddlNT = $this->dba->createTable($obsArray);
                 $this->passNumber++;
             }
             // Insert data
             $ddlNT = $this->dba->insertRows($obsArray, $ddlNT, $insertCounter);
         }

         return($ddlNT);
     }

     public function bForms($data, $ddlNT, $insertCounter)
     {
         $nbRow = 0;
         reset($data);

         if ($this->tracing) $this->log->trace(_('Analyse d\'un formulaire'));
         foreach ($data as $key => $value) {
             $nbRow = $nbRow + 1;
             if ($this->tracing) $this->log->trace(_('Elément forms numéro : ') . $nbRow);
             $obsInit = array();
             foreach ($value as $keyS => $valueS) {
                 switch ($keyS) {
                     case 'id_form_universal':
                         if ($this->tracing) $this->log->trace('  id_form_universal => ' . $valueS);
                         $obsInit['id_form_universal'] = $valueS;
                         break;
                     case 'time_start':
                         if ($this->tracing) $this->log->trace('  time_start => ' . $valueS);
                         $obsInit['time_start'] = $valueS;
                         break;
                     case 'time_stop':
                         if ($this->tracing) $this->log->trace('  time_stop => ' . $valueS);
                         $obsInit['time_stop'] = $valueS;
                         break;
                     case 'sightings':
                         $ddlNT = $this->bSightings($valueS, $ddlNT, $insertCounter, $obsInit);
                         break;
                     default:
                         if ($this->tracing) $this->log->trace(_('Element forms non traité : ') . $keyS);
                 }
             }
         }

         return($ddlNT);
     }

     public function parse($response, $insertCounter)
     {
         $this->log->debug(_('Analyse des données json de ') . $this->table);

         // Drop table on first loop. It will be created after parsing.
         if ($this->passNumber == 0) {
             $this->dba->dropTable();
         }

         if ($this->tracing) $this->log->trace(_('Début de l\'analyse des ' . $this->table));
         $data = json_decode($response, true);

         // Count sightings and forms in the file
         $sightings = (is_array($data) && (array_key_exists('sightings', $data['data']))) ?
                         count($data['data']['sightings']) :
                         0;
         $forms = (is_array($data) && (array_key_exists('forms', $data['data']))) ?
                      count($data['data']['forms']) :
                      0;

         // Empty file => exit
         if ($sightings + $forms == 0) {
             $this->log->debug(_('Fichier de données vide'));
         } else {
             $this->log->debug(_('Chargement de ') . $sightings . ' élements sightings');
             $this->log->debug(_('Chargement de ') . $forms . ' élements forms');
             reset($data);
             foreach ($data['data'] as $key => $value) {
                 if ($this->tracing) $this->log->trace(_('Analyse de l\'élement : ') . $key);
                 switch ($key) {
                     case 'sightings':
                         $obsInit = array();
                         $this->ddlNT = $this->bSightings($value, $this->ddlNT, $insertCounter, $obsInit);
                         break;
                     case 'forms':
                         $this->ddlNT = $this->bForms($value, $this->ddlNT, $insertCounter);
                         break;
                     default:
                         $this->log->warn(_('Element racine inconnu: ') . $key);
                 }
             }
             $this->log->debug(_('Fin de l\'analyse d\'un fichier d\'observations'));
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

$logger->info(_('Début de l\'import - version : ') . file_get_contents('version.txt'));
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
$entities = new StoreFile($dbh, 'entities', 'ParseData', $options['file_store']);
$entities->store();
unset($entities);

// Store export_organizations in database
$export_organizations = new StoreFile($dbh, 'export_organizations', 'ParseData', $options['file_store']);
$export_organizations->store();
unset($export_organizations);

// Store families in database
$families = new StoreFile($dbh, 'families', 'ParseData', $options['file_store']);
$families->store();
unset($families);

// Store grids in database
$grids = new StoreFile($dbh, 'grids', 'ParseData', $options['file_store']);
$grids->store();
unset($grids);

// Store local_admin_units in database
$local_admin_units = new StoreFile($dbh, 'local_admin_units', 'ParseData', $options['file_store']);
$local_admin_units->store();
unset($local_admin_units);

// Store observations in database
$observations = new StoreFile($dbh, 'observations', 'ObsParser', $options['file_store']);
$observations->store();
unset($observations);

// Store places in database
$places = new StoreFile($dbh, 'places', 'ParseData', $options['file_store']);
$places->store();
unset($places);

// Store species in database
$species = new StoreFile($dbh, 'species', 'ParseData', $options['file_store']);
$species->store();
unset($species);

// Store taxo_groups in database
$taxo_groups = new StoreFile($dbh, 'taxo_groups', 'ParseData', $options['file_store']);
$taxo_groups->store();
unset($taxo_groups);

// Store territorial_units in database
$territorial_units = new StoreFile($dbh, 'territorial_units', 'ParseData', $options['file_store']);
$territorial_units->store();
unset($territorial_units);

// Close database connection
unset($dbh);

// Print summary of DB insertions
foreach ($DbInsertions as $file => $ins){
    $logger->debug(_('Insertion de ' . $ins->NbInserted() . _(" lignes depuis le fichier ") . $file));
}
