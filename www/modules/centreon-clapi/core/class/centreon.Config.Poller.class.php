<?php
/*
 * Copyright 2005-2015 CENTREON
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give CENTREON
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of CENTREON choice, provided that
 * CENTREON also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 * SVN : $URL$
 * SVN : $Id$
 *
 */

require_once "centreonUtils.class.php";
require_once "centreonClapiException.class.php";

/**
 *
 * @author Julien Mathis
 *
 */
class CentreonConfigPoller {
    private $_DB;
    private $_DBC;
    private $resultTest;
    private $optGen;
    private $nagiosCFGPath;
    private $centreon_path;
    private $centcore_pipe;
    const MISSING_POLLER_ID = "Missing poller ID";

    /**
     * Constructor
     * @param CentreonDB $DB
     * @param string $centreon_path
     * @param CentreonDB $DBC
     * @return void
     */
    public function __construct($DB, $centreon_path, $DBC) {
        $this->_DB = $DB;
        $this->_DBC = $DBC;
        $this->resultTest = 0;
        $this->nagiosCFGPath = "$centreon_path/filesGeneration/nagiosCFG/";
        $this->centreon_path = $centreon_path;
        $this->resultTest = array("warning" => 0, "errors" => 0);
        $this->centcore_pipe = "@CENTREON_VARLIB@/centcore.cmd";
    }

    /**
     * Get General option of Centreon
     */
    private function getOptGen() {
        $DBRESULT =& $this->_DB->query("SELECT * FROM options");
        while ($row =& $DBRESULT->fetchRow()) {
            $this->optGen[$row["key"]] = $row["value"];
        }
        $DBRESULT->free();
        unset($row);
    }
    /**
     * 
     * @param type $poller
     * @return type
     */
    private function testPollerId($poller)
    {
        if (is_numeric($poller)) {
            $sQuery = "SELECT id FROM nagios_server WHERE `id` = '".$this->_DB->escape($poller)."'";
        } else {
            $sQuery = "SELECT id FROM nagios_server WHERE `name` = '".$this->_DB->escape($poller)."'";
        }
        
        $DBRESULT =& $this->_DB->query($sQuery);
        if ($DBRESULT->numRows() != 0)
            return;
        else {
            print "ERROR: Unknown poller...\n";
            $this->getPollerList($this->format);
            exit(1);
        }
    }
    /**
     * 
     * @param type $poller
     * @return type
     */
    private function isPollerLocalhost($poller)
    {
        if (is_numeric($poller)) {
            $sQuery = "SELECT localhost FROM nagios_server WHERE `id` = '".$this->_DB->escape($poller)."'";
        } else {
            $sQuery = "SELECT localhost FROM nagios_server WHERE `name` = '".$this->_DB->escape($poller)."'";
        }
        
        $DBRESULT =& $this->_DB->query($sQuery);
        if ($data =& $DBRESULT->fetchRow())
            return $data["localhost"];
        else {
            print "ERROR: Unknown poller...\n";
            $this->getPollerList($this->format);
            exit(1);
        }

    }

    /**
     * Returns monitoring engines for generation purpose
     *
     * @param int $poller
     * @return string
     */
    private function getMonitoringEngine($poller)
    {
        if (is_numeric($poller)) {
            $sQuery = "SELECT monitoring_engine FROM nagios_server WHERE `id` = " . $this->_DB->escape($poller);
        } else {
            $sQuery = "SELECT monitoring_engine FROM nagios_server WHERE `name` = '".$this->_DB->escape($poller)."'";
        }
        
        $res = $this->_DB->query($sQuery);
        
        $row = $res->fetchRow();
        if (isset($row['monitoring_engine'])) {
            return $row['monitoring_engine'];
        }
        return "";
    }
    /**
     * 
     * @param type $format
     * @return int
     */
    public function getPollerList($format)
    {
        $DBRESULT =& $this->_DB->query("SELECT id,name FROM nagios_server WHERE ns_activate = '1' ORDER BY id");
        if ($format == "xml") {
            print "";
        }
        while ($data =& $DBRESULT->fetchRow()) {
            print $data["id"]."\t".$data["name"]."\n";
        }
        $DBRESULT->free();
        unset($data);
        return 0;
    }

    /**
     * Restart Centreon broker
     */
    protected function restartCentreonBroker() {
        if (file_exists($this->centreon_path."/www/class/centreonBroker.class.php")) {
            require_once $this->centreon_path."/www/class/centreonBroker.class.php";
            $brk = new CentreonBroker($this->_DB);
            if ($brk->getBroker() == 'broker') {
                $brk->reload();
            }
        }
    }

    /**
     *
     * Reload a server
     * @param unknown_type $variables
     */
    public function pollerReload($variables) {
        $return_value = 0;

        if (!isset($variables)) {
            print "Cannot get poller";
            exit(1);
        }

        $this->testPollerId($variables);
        
        if (!is_numeric($variables)) {
            $variables = $this->getPollerId($variables);
        }

        /*
         * Restart broker
         */
        $this->restartCentreonBroker();

        /*
         * Get Init Script
         */
        $DBRESULT =& $this->_DB->query("SELECT id, init_script FROM nagios_server WHERE localhost = '1' AND ns_activate = '1'");
        $serveurs =& $DBRESULT->fetchrow();
        $DBRESULT->free();
        (isset($serveurs["init_script"])) ? $nagios_init_script = $serveurs["init_script"] : $nagios_init_script = "/etc/init.d/nagios";
        unset($serveurs);

        $DBRESULT =& $this->_DB->query("SELECT * FROM `nagios_server` WHERE `id` = '".$this->_DB->escape($variables)."'  LIMIT 1");
        $host = $DBRESULT->fetchRow();
        $DBRESULT->free();

        $msg_restart = "";
        if (isset($host['localhost']) && $host['localhost'] == 1) {
            $msg_restart = exec("sudo " . $nagios_init_script . " reload", $stdout, $return_code);
        } else {
            exec("echo 'RELOAD:".$host["id"]."' >> ". $this->centcore_pipe, $stdout, $return_code);
            $msg_restart .= _("OK: A reload signal has been sent to '".$host["name"]."'");
        }
        print $msg_restart."\n";
        $DBRESULT =& $this->_DB->query("UPDATE `nagios_server` SET `last_restart` = '".time()."' WHERE `id` = '".$this->_DB->escape($variables)."' LIMIT 1");
        return $return_code;
    }

    /**
     * Execute post generation command
     *
     * @param int $pollerId
     * @throws CentreonClapiException
     */
    public function execCmd($pollerId)
    {
        $this->testPollerId($pollerId);
        
        $instanceClassFile = $this->centreon_path . 'www/class/centreonInstance.class.php';
        if (!is_file($instanceClassFile)) {
            throw new CentreonClapiException('This action is not available in the version of Centreon you are using');
        }
        require_once $instanceClassFile;
        
        if (!is_numeric($pollerId)) {
            $pollerId = $this->getPollerId($pollerId);
        }

        $instanceObj = new CentreonInstance($this->_DB);
        $cmds = $instanceObj->getCommandData($pollerId);
        $result = 0;
        foreach ($cmds as $cmd) {
            echo "Executing command {$cmd['command_name']}... ";
            exec($cmd['command_line'], $output, $cmdResult);
            if ($cmdResult) {
                $resultStr = "Error: {$output}";
                $result += $cmdResult;
            } else {
                $resultStr = "OK";
            }
            echo "{$resultStr}\n";
        }
        // if result > 0, return 1, return 0 otherwise 
        return ($result ? 1 : 0);
    }

    /**
     *
     * Restart a serveur
     * @param unknown_type $variables
     */
    public function pollerRestart($variables)
    {
        if (!isset($variables)) {
            print "Cannot get poller";
            exit(1);
        }

        $this->testPollerId($variables);
        
        if (!is_numeric($variables)) {
            $variables = $this->getPollerId($variables);
        }

        /*
         * Restart broker
         */
        $this->restartCentreonBroker();

        /*
         * Get Init Script
         */
        $DBRESULT =& $this->_DB->query("SELECT id, init_script FROM nagios_server WHERE localhost = '1' AND ns_activate = '1'");
        $serveurs =& $DBRESULT->fetchrow();
        $DBRESULT->free();
        (isset($serveurs["init_script"])) ? $nagios_init_script = $serveurs["init_script"] : $nagios_init_script = "/etc/init.d/nagios";
        unset($serveurs);

        $DBRESULT =& $this->_DB->query("SELECT * FROM `nagios_server` WHERE `id` = '".$this->_DB->escape($variables)."'  LIMIT 1");
        $host = $DBRESULT->fetchRow();
        $DBRESULT->free();

        $msg_restart = "";
        if (isset($host['localhost']) && $host['localhost'] == 1) {
            $msg_restart = exec(escapeshellcmd("sudo " . $nagios_init_script . " restart"), $lines, $return_code);
        } else {
            exec("echo 'RESTART:".$variables."' >> ". $this->centcore_pipe, $stdout, $return_code);
            $msg_restart = _("OK: A restart signal has been sent to '".$host["name"]."'");
        }
        print $msg_restart."\n";
        $DBRESULT =& $this->_DB->query("UPDATE `nagios_server` SET `last_restart` = '".time()."' WHERE `id` = '".$this->_DB->escape($variables)."' LIMIT 1");
        return $return_code;
    }

    /**
     *
     * Test poller configuration
     * @param unknown_type $format
     * @param unknown_type $variables
     */
    public function pollerTest($format, $variables)
    {
        if (!isset($variables)) {
            print "Cannot get poller";
            exit(1);
        }

        $this->testPollerId($variables);
        
        if (is_numeric($variables)) {
            $idPoller = $variables;
        } else {
            $idPoller = $this->getPollerId($variables);
        }

        /**
         * Get Nagios Bin
         */
        $DBRESULT_Servers =& $this->_DB->query("SELECT `nagios_bin` FROM `nagios_server` WHERE `localhost` = '1' ORDER BY `ns_activate` DESC LIMIT 1");
        $nagios_bin = $DBRESULT_Servers->fetchRow();
        $DBRESULT_Servers->free();

        /*
         * Launch test command
         */
        if (isset($nagios_bin["nagios_bin"])) {
            exec(escapeshellcmd("sudo ".$nagios_bin["nagios_bin"] . " -v ".$this->nagiosCFGPath.$idPoller."/nagiosCFG.DEBUG"), $lines, $return_code);
        } else {
            throw new CentreonClapiException("Can't find engine binary");
        }

        $msg_debug = "";
        foreach ($lines as $line) {
            if (strncmp($line, "Processing object config file", strlen("Processing object config file"))
                    && strncmp($line, "Website: http://www.nagios.org", strlen("Website: http://www.nagios.org"))) {
                $msg_debug .= $line . "\n";

                /**
                 * Detect Errors
                 */
                if (preg_match("/Total Warnings: ([0-9])*/", $line, $matches))
                    if (isset($matches[1])) {
                        $this->resultTest["warning"] = $matches[1];
                    }
                if (preg_match("/Total Errors: ([0-9])*/", $line, $matches))
                    if (isset($matches[1])) {
                        $this->resultTest["errors"] = $matches[1];
                    }
                if (preg_match("/^Error:/", $line, $matches))
                    $this->resultTest["errors"]++;
                if (preg_match("/^Errors:/", $line, $matches))
                    $this->resultTest["errors"]++;
            }
        }
        if ($this->resultTest["errors"] != 0) {
            print "Error: Nagios Poller $variables cannot restart. configuration broker. Please see debug bellow :\n";
            print "---------------------------------------------------------------------------------------------------\n";
            print $msg_debug."\n";
            print "---------------------------------------------------------------------------------------------------\n";
        } else if ($this->resultTest["warning"] != 0) {
            print "Warning: Nagios Poller $variables can restart but configuration is not optimal. Please see debug bellow :\n";
            print "---------------------------------------------------------------------------------------------------\n";
            print $msg_debug."\n";
            print "---------------------------------------------------------------------------------------------------\n";
        } elseif ($return_code) {
            print implode("\n", $lines);
        } else {
            print "OK: Nagios Poller $variables can restart without problem...\n";
        }
        return $return_code;
    }

    /**
     *
     * Generate configuration files for a specific poller
     * @param $variables
     * @param $login
     * @param $password
     */
    public function pollerGenerate($variables, $login, $password) {
        require_once "../../../include/configuration/configGenerate/DB-Func.php";
        require_once "../../../include/common/common-Func.php";

        $this->testPollerId($variables);
        $tab["localhost"] = $this->isPollerLocalhost($variables);

        $centreon_path = $this->centreon_path;
        global $pearDB, $pearDBO;
        $pearDB = $this->_DB;
        $pearDBO = $this->_DBC;

        $nagiosCFGPath = $this->nagiosCFGPath;
        $DebugPath = "filesGeneration/nagiosCFG/";

        $ret["comment"] = 0;

        /**
         * Init environnement
         */
        if (strncmp($this->optGen["version"], "2.1", 3)) {
            require_once $this->centreon_path."/www/class/centreon.class.php";
        } else {
            require_once $this->centreon_path."/www/class/Oreon.class.php";
        }

        require_once $this->centreon_path."/www/class/centreonDB.class.php";
        require_once $this->centreon_path."/www/class/centreonAuth.class.php";
        require_once $this->centreon_path."/www/class/centreonLog.class.php";
        require_once $this->centreon_path."/www/class/centreonConfigCentreonBroker.php";

        global $oreon, $centreon, $_SERVER;

        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";

        chdir("../../..");

        $CentreonLog = new CentreonUserLog(-1, $pearDB);
        $centreonAuth = new CentreonAuth($login, $password, 0, $this->_DB, $CentreonLog, NULL);
        if (strncmp($this->optGen["version"], "2.1", 3)) {
            $oreon = new Centreon((array)$centreonAuth->userInfos);
            $oreon->user->version = 3;
        } else {
            $user = new User($centreonAuth->userInfos, $this->optGen["nagios_version"]);
            $oreon = new Oreon($user);
            $oreon->user->version = 3;
        }
        $centreon = $oreon;
        if (is_numeric($variables)) {
            $tab['id'] = $variables;
        } else {
            $tab['id'] = $this->getPollerId($variables);
        }

        $tab['monitoring_engine'] = $this->getMonitoringEngine($variables);

        chdir("./modules/centreon-clapi/core/");

        /**
         * Insert session in session table
         */
        session_id(1);
        $pearDB->query(
            "INSERT INTO `session` (`session_id` , `user_id` , `current_page` , `last_reload`, `ip_address`) 
            VALUES ('1', '".$oreon->user->user_id."', '1', '".time()."', '".$_SERVER["REMOTE_ADDR"]."')"
        );

        /**
         * Generate dependancies tree.
         */
        global $gbArr;
        $gbArr = manageDependencies();
        $centreonBrokerPath = $this->centreon_path . "/filesGeneration/broker/";

        /**
         * Generate Configuration
         */
        $path = "../../../include/configuration/configGenerate/";
        $path2 = "./include/configuration/configGenerate/";

        require $path."genCGICFG.php";

        chdir("../../..");

        require $path2."genNagiosCFG.php";
        require $path2."genNagiosCFG-DEBUG.php";

        chdir("./modules/centreon-clapi/core/");

        require $path."genNdomod.php";
        require $path."genNdo2db.php";
        require $path."genCentreonBroker.php";
        require $path."genResourceCFG.php";
        global $Period;
        require $path."genTimeperiods.php";
        require $path."genCommands.php";
        if (file_exists($path."genConnectors.php")) {
            require $path."genConnectors.php";
        }
        require $path."genContacts.php";
        if (file_exists($path."genContactTemplates.php")) {
            require $path."genContactTemplates.php";
        }
        require $path."genContactGroups.php";
        require $path."genHosts.php";
        require $path."genHostTemplates.php";
        require $path."genHostGroups.php";
        require $path."genServiceTemplates.php";
        require $path."genServices.php";
        require $path."genServiceGroups.php";
        require $path."genEscalations.php";
        require $path."genDependencies.php";
        require $path."centreon_pm.php";

        chdir("../../..");

        if (isset($tab['localhost']) && $tab['localhost']) {
            $flag_localhost = $tab['localhost'];
            /*
             * Meta Services Generation
             */
            if ($files = glob("./include/configuration/configGenerate/metaService/*.php")) {
                foreach ($files as $filename) {
                    require_once($filename);
                }
            }
        }

        /*
         * Module Generation
         */
        foreach ($oreon->modules as $key => $value) {
            $flag_localhost = $tab['localhost'];
            if (file_exists("./modules/".$key."/core/common/functions.php")) {
                require_once "./modules/".$key."/core/common/functions.php";
            }
            if ($value["gen"] && $files = glob("./modules/".$key."/generate_files/*.php")) {
                foreach ($files as $filename) {
                    require_once ($filename);
                }
            }
        }

        chdir("./modules/centreon-clapi/core/");
        unset($generatedHG);
        unset($generatedSG);
        unset($generatedS);

        if (file_exists($path."genIndexData.php")) {
            require_once $path."genIndexData.php";
        }
        
        print "Configuration files generated for poller '".$variables."'\n";

        /* free session */
        $pearDB->query("DELETE FROM `session` 
            WHERE `session_id` = '1' 
            AND `user_id` = '".$oreon->user->user_id."'");

        return 0;
    }

    /**
     *
     * Move configuration files to servers
     * @param unknown_type $variables
     */
    public function cfgMove($variables) {
        global $pearDB, $pearDBO;

        $pearDB = $this->_DB;
        $pearDBO = $this->_DBC;

        require_once "../../../include/configuration/configGenerate/DB-Func.php";
        if (!isset($variables)) {
            print "Cannot get poller";
            exit(1);
        }

        $return = 0;

        /**
         * Check poller existance
         */
        $this->testPollerId($variables);
        
        if (!is_numeric($variables)) {
            $variables = $this->getPollerId($variables);
        }

        /**
         * Move files.
         */
        $DBRESULT_Servers =& $this->_DB->query("SELECT `cfg_dir` FROM `cfg_nagios` WHERE `nagios_server_id` = '".$this->_DB->escape($variables)."' LIMIT 1");
        $Nagioscfg = $DBRESULT_Servers->fetchRow();
        $DBRESULT_Servers->free();

        $DBRESULT_Servers =& $this->_DB->query("SELECT * FROM `nagios_server` WHERE `id` = '".$this->_DB->escape($variables)."'  LIMIT 1");
        $host = $DBRESULT_Servers->fetchRow();
        $DBRESULT_Servers->free();
        if (isset($host['localhost']) && $host['localhost'] == 1) {
            $msg_copy = "";
            foreach (glob($this->nagiosCFGPath.$variables."/*.cfg") as $filename) {
                $bool = @copy($filename , $Nagioscfg["cfg_dir"]."/".basename($filename));
                $filename = array_pop(explode("/", $filename));
                if (!$bool) {
                    $msg_copy .= $this->display_copying_file($filename, " - "._("movement")." KO");
                    $return = 1;
                }
            }

            /*
             * Centreon Broker configuration
             */
            $centreonBrokerPath = $this->centreon_path."/filesGeneration/broker/";
            $listBrokerFile = glob($centreonBrokerPath . $host['id'] . "/*.xml");
            if (count($listBrokerFile) > 0) {
                $centreonBrokerDirCfg = getCentreonBrokerDirCfg($host['id']);
                if (!is_null($centreonBrokerDirCfg)) {
                    if (!is_dir($centreonBrokerDirCfg)) {
                        if (!mkdir($centreonBrokerDirCfg, 0755)) {
                            throw new Exception(sprintf(_("Centreon Broker's configuration directory '%s' does not exist and could not be created for monitoring engine '%s'. Please check it's path or create it"), $centreonBrokerDirCfg, $host['name']));
                        }
                    }
                    foreach ($listBrokerFile as $fileCfg) {
                        $succeded = @copy($fileCfg, rtrim($centreonBrokerDirCfg, "/") . '/' . basename($fileCfg));
                        if (!$succeded) {
                            throw new Exception(sprintf(_("Could not write to Centreon Broker's configuration file '%s' for monitoring engine '%s'. Please add writing permissions for the webserver's user"), basename($fileCfg), $host['name']));
                        }
                    }
                }
            }


            if (strlen($msg_copy) == 0) {
                $msg_copy .= _("OK: All configuration files copied with success.");
            }
        } else {
            exec("echo 'SENDCFGFILE:".$host['id']."' >> ".$this->centcore_pipe, $stdout, $return);
            if (!isset($msg_copy)) {
                $msg_copy = "";
            }
            $msg_copy .= _("OK: All configuration will be send to '".$host['name']."' by centcore in several minutes.");
        }
        print $msg_copy."\n";
        return $return;
    }

    /**
     * Send Trap configuration files to poller
     *
     * @param int $pollerId
     * @return void
     * @throws CentreonClapiException
     */
    public function sendTrapCfg($pollerId = null)
    {
        if (is_null($pollerId)) {
            throw new CentreonClapiException(self::MISSING_POLLER_ID);
        }
        $this->testPollerId($pollerId);
        $centreonDir = CentreonUtils::getCentreonDir();
        passthru("$centreonDir/bin/centGenSnmpttConfFile 2>&1");
        exec("echo 'SYNCTRAP:".$pollerId."' >> ".$this->centcore_pipe, $stdout, $return);
        return $return;
    }

    /**
     *
     * Display Copying files
     * @param unknown_type $filename
     * @param unknown_type $status
     */
    private function display_copying_file($filename = NULL, $status){
        if (!isset($filename)) {
            return ;
        }
        $str = "- ".$filename." -> ".$status."\n";
        return $str;
    }
    
    /**
     * 
     * @param type $poller
     * @return type
     */
    private function getPollerId($poller)
    {
        if (is_string($poller)) {
            $sQuery = "SELECT id FROM nagios_server WHERE `name` = '".$this->_DB->escape($poller)."'";
        }
        
        $DBRESULT =& $this->_DB->query($sQuery);
        if ($DBRESULT->numRows() != 1)
            return;
        else {
            $row = $DBRESULT->fetchRow();
            return $row['id'];
        }
    }
}
?>
