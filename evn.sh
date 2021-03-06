#!/bin/bash
#
# Main shell to call the different Export PHP scripts.
#
# Parameters:
#     $1: type of action to perform:
#          - config => Configure parameters for scripts (sites, passwords...).
#          - init => Prepare DB init script.
#          - download => Export from VisioNature fo json files, using API.
#          - store => Load json files in Postgresql.
#          - all => download then store
#
# Copyright (c) 2016, Daniel Thonon
#  All rights reserved.
#
#  Redistribution and use in source and binary forms, with or without modification,
#  are permitted provided that the following conditions are met:
# 1. Redistributions of source code must retain the above copyright notice,
#    this list of conditions and the following disclaimer.
# 2. Redistributions in binary form must reproduce the above copyright notice,
#    this list of conditions and the following disclaimer in the documentation and/or
#    other materials provided with the distribution.
# 3. Neither the name of the copyright holder nor the names of its contributors
#    may be used to endorse or promote products derived from this software without
#    specific prior written permission.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
# ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
# IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
# INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA,
# OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
# WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
# ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.

cmd=$1

# When running from crontab. To be improved
cd ~/ExportVN

# Logging file
evn_log=~/evn_all_$(date '+%Y-%m-%d').log

# Default mail address for results mail, overriden by config file
config[evn_admin_mail]="d.thonon9@gmail.com"

# Load configuration file, if present, else ask for configuration
evn_conf=~/.evn.conf
unset config      # clear parameter array
typeset -A config # init array

# echo "commande = $cmd"
if [[ -f $evn_conf ]]  # Check if exists and load existing config
then
    echo "$(date '+%F %T') - INFO - Lancement de l'export des données"
    # Parse configuration file
    while read line
    do
        # echo $line
        if echo $line | grep -F = &>/dev/null
        then
            varname=$(echo "$line" | cut -d '=' -f 1)
            config[$varname]=$(echo "$line" | cut -d '=' -f 2-)
        fi
    done < $evn_conf
else
    echo "$(date '+%F %T') - INFO - Configuration initiale"
    cmd=config
    # Prepare default values
    config[evn_db_host]="localhost"
    config[evn_db_port]="5432"
    config[evn_logging]="INFO"
fi

case "$cmd" in
    config)
        read -e -p "Site VisioNature : " -i "${config[evn_site]}" evn_site
        echo "evn_site=$evn_site" > $evn_conf
        read -e -p "Compte VisioNature : " -i "${config[evn_user_email]}" evn_user_email
        echo "evn_user_email=$evn_user_email" >> $evn_conf
        read -e -p "Mot de passe VisioNature : " -i "${config[evn_user_pw]}" evn_user_pw
        echo "evn_user_pw=$evn_user_pw" >> $evn_conf
        read -e -p "Consumer key VisioNature : " -i "${config[evn_consumer_key]}" evn_consumer_key
        echo "evn_consumer_key=$evn_consumer_key" >> $evn_conf
        read -e -p "Consumer secret VisioNature : " -i "${config[evn_consumer_secret]}" evn_consumer_secret
        echo "evn_consumer_secret=$evn_consumer_secret" >> $evn_conf
        read -e -p "Répertoire de stockage des fichiers json reçus : " -i "${config[evn_file_store]}" evn_file_store
        echo "evn_file_store=$evn_file_store" >> $evn_conf

        read -e -p "Hôte de la base postgresql : " -i "${config[evn_db_host]}" evn_db_host
        echo "evn_db_host=$evn_db_host" >> $evn_conf
        read -e -p "Port de la base postgresql : " -i "${config[evn_db_port]}" evn_db_port
        echo "evn_db_port=$evn_db_port" >> $evn_conf
        read -e -p "Nom de la base postgresql : " -i "${config[evn_db_name]}" evn_db_name
        echo "evn_db_name=$evn_db_name" >> $evn_conf
        read -e -p "Schéma de la base postgresql : " -i "${config[evn_db_schema]}" evn_db_schema
        echo "evn_db_schema=$evn_db_schema" >> $evn_conf
        read -e -p "Groupe/rôle de la base postgresql : " -i "${config[evn_db_group]}" evn_db_group
        echo "evn_db_group=$evn_db_group" >> $evn_conf
        read -e -p "Compte/rôle de la base postgresql : " -i "${config[evn_db_user]}" evn_db_user
        echo "evn_db_user=$evn_db_user" >> $evn_conf
        read -e -p "Mot de passe de la base postgresql : " -i "${config[evn_db_pw]}" evn_db_pw
        echo "evn_db_pw=$evn_db_pw" >> $evn_conf
        read -e -p "Répertoire des fichiers scripts sql spécifiques : " -i "${config[evn_sql_scripts]}" evn_sql_scripts
        echo "evn_sql_scripts=$evn_sql_scripts" >> $evn_conf

        read -e -p "Destinataire des mails de suivi : " -i "${config[evn_admin_mail]}" evn_admin_mail
        echo "evn_admin_mail=$evn_admin_mail" >> $evn_conf

        read -e -p "Niveau de logging [TRACE/DEBUG/INFO] : " -i "${config[evn_logging]}" evn_logging
        echo "evn_logging=$evn_logging" >> $evn_conf
        ;;

    init)
        # Create directories as needed
        if [[ ! -d ~/${config[evn_file_store]} ]]
        then
            mkdir ~/${config[evn_file_store]}
        fi
        if [[ ! -d ~/${config[evn_file_store]}/backup ]]
        then
            mkdir ~/${config[evn_file_store]}/backup
        fi
        if [[ ! -d ~/${config[evn_sql_scripts]} ]]
        then
            mkdir ~/${config[evn_sql_scripts]}
        fi

        # Prepare SQL init script
        cp SQL_templates/InitDB.sql /tmp/InitDB.tmp
        sed -i -e "s/evn_db_name/${config[evn_db_name]}/" /tmp/InitDB.tmp
        sed -i -e "s/evn_db_schema/${config[evn_db_schema]}/" /tmp/InitDB.tmp
        sed -i -e "s/evn_db_group/${config[evn_db_group]}/" /tmp/InitDB.tmp
        sed -i -e "s/evn_db_user/${config[evn_db_user]}/" /tmp/InitDB.tmp
        sed -i -e "s/evn_db_pw/${config[evn_db_pw]}/" /tmp/InitDB.tmp
        mv /tmp/InitDB.tmp /tmp/Init_${config[evn_db_name]}.sql

        echo "Pour (re)créer la base de données, exécutez la commande suivante depuis le compte postgres"
        echo "$ psql -f /tmp/Init_${config[evn_db_name]}.sql"
        ;;

    download)
        echo "$(date '+%F %T') - INFO - Téléchargement depuis le site : ${config[evn_site]} à $(date)"
        # Check directories
        if [[ ! -d ~/${config[evn_file_store]} ]]
        then
            mkdir ~/${config[evn_file_store]}
        fi
        if [[ ! -d ~/${config[evn_file_store]}/backup ]]
        then
            mkdir ~/${config[evn_file_store]}/backup
        fi
        if [[ ! -d ~/${config[evn_sql_scripts]} ]]
        then
            mkdir ~/${config[evn_sql_scripts]}
        fi
        # Remove previous downloaded files
        echo "$(date '+%F %T') - INFO - Déplacement des fichiers précédents"
        mv -f ~/${config[evn_file_store]}/*.json ~/${config[evn_file_store]}/backup

        # Download from biolovision and store in json
        php ~/ExportVN/ExportJson.php \
        --site=${config[evn_site]} \
        --user_email=${config[evn_user_email]} \
        --user_pw=${config[evn_user_pw]} \
        --consumer_key=${config[evn_consumer_key]} \
        --consumer_secret=${config[evn_consumer_secret]} \
        --file_store=${config[evn_file_store]} \
        --logging=${config[evn_logging]}

        echo "$(date '+%F %T') - INFO - Fin de l'export"
        ;;

    store)
        # Prepare password for psql
        echo "${config[evn_db_host]}:${config[evn_db_port]}:${config[evn_db_name]}:${config[evn_db_user]}:${config[evn_db_pw]}" > ~/.pgpass
        chmod 0600 ~/.pgpass

        # Pre-processing sql script
       if [[ -f ~/${config[evn_sql_scripts]}/Pre_store.sql ]]  # Check if script exists
       then
           echo "$(date '+%F %T') - INFO - Préparation du chargement dans la base ${config[evn_db_name]}"
           env PGOPTIONS="-c search_path=${config[evn_db_schema]},public -c client-min-messages=WARNING" \
               psql -q -h ${config[evn_db_host]} -p ${config[evn_db_port]} -U ${config[evn_db_user]} \
               -d "dbname=${config[evn_db_name]}" -f ~/${config[evn_sql_scripts]}/Pre_store.sql
       fi

        # Store downloaded json to postgres db
       echo "$(date '+%F %T') - INFO - Chargement des fichiers json dans la base ${config[evn_db_name]}"
       php ~/ExportVN/ChargePsql.php \
       --db_host=${config[evn_db_host]} \
       --db_port=${config[evn_db_port]} \
       --db_name=${config[evn_db_name]} \
       --db_schema=${config[evn_db_schema]} \
       --db_user=${config[evn_db_user]} \
       --db_pw=${config[evn_db_pw]}\
       --file_store=${config[evn_file_store]} \
       --logging=${config[evn_logging]}

        echo "$(date '+%F %T') - INFO - Finalisation de la base de données"
        cp -f SQL_templates/ChargePsql.sql ~/${config[evn_sql_scripts]}/ChargePsql.sql
        sed -i -e "s/evn_db_group/${config[evn_db_group]}/" ~/${config[evn_sql_scripts]}/ChargePsql.sql
        env PGOPTIONS="-c search_path=${config[evn_db_schema]},public -c client-min-messages=WARNING" \
            psql -q -h ${config[evn_db_host]} -p ${config[evn_db_port]} -U ${config[evn_db_user]} \
            -d "dbname=${config[evn_db_name]}" -f ~/${config[evn_sql_scripts]}/ChargePsql.sql

        # Post-processing sql script
        if [[ -f ~/${config[evn_sql_scripts]}/Post_store.sql ]]  # Check if script exists
        then
            env PGOPTIONS="-c search_path=${config[evn_db_schema]},public -c client-min-messages=WARNING" \
                psql -q -h ${config[evn_db_host]} -p ${config[evn_db_port]} -U ${config[evn_db_user]} \
                -d "dbname=${config[evn_db_name]}" -f ~/${config[evn_sql_scripts]}/Post_store.sql
        fi

       rm -f ~/.pgpass
        echo "$(date '+%F %T') - INFO - Fin du chargement dans la base "
        ;;

    all)
        echo "$(date '+%F %T') - INFO - Début téléchargement depuis le site : ${config[evn_site]}" > $evn_log
        $0 download >> $evn_log
        links -dump ${config[evn_site]}index.php?m_id=23 | fgrep "Les part" | sed 's/Les partenaires       /Total des contributions :/' > ~/mail_fin.txt
        echo "$(date '+%F %T') - INFO - Chargement des fichiers json dans la base ${config[evn_db_name]}" >> $evn_log
        $0 store >> $evn_log
        echo "$(date '+%F %T') - INFO - Fin transfert depuis le site : ${config[evn_site]}" >> $evn_log
        links -dump ${config[evn_site]}index.php?m_id=23 | fgrep "Les part" | sed 's/Les partenaires       /Total des contributions :/' >> $evn_log
        echo "Bilan du script : ERROR / WARN :" >> ~/mail_fin.txt
        fgrep -c "ERROR" $evn_log >> ~/mail_fin.txt
        fgrep -c "WARN" $evn_log >> ~/mail_fin.txt
	    tail -15 $evn_log >> ~/mail_fin.txt
        gzip -f $evn_log
        echo "$(date '+%F %T') - INFO - Fin de l'export des données"
        mailx -s "Chargement de ${config[evn_site]}" -a $evn_log.gz ${config[evn_admin_mail]} < ~/mail_fin.txt
        rm -f ~/mail_fin.txt
        ;;

    *)
        echo "Usage: $SCRIPTNAME {config|init|download|store|all}" >&2
        ;;

esac

exit 0
