#!/bin/bash
#
# Main shell to call the different Export PHP scripts.
#
# Parameters:
#     $1: type of action to perform:
#          - config => Configure parameters for scripts (sites, passwords...).
#          - init => Prepare DB init script.
#          - download => Export from VisioNature fo json files, using API.
#          - load => Load json files in Postgresql.
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

# Load configuration file, if present, else ask for configuration
evn_conf=~/.evn.conf
unset config # clear parameter array
typeset -A config # init array

# echo "commande = $cmd"
if [[ -f $evn_conf ]]  # Check if exists and load existing config
then
	echo "Chargement de la configuration"
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
	echo "Configuration initiale"
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

        read -e -p "Niveau de logging [TRACE/DEBUG/INFO] : " -i "${config[evn_logging]}" evn_logging
		echo "evn_logging=$evn_logging" >> $evn_conf
 	;;

    init)
        cp InitDB.sql InitDB.tmp
        sed -i -e "s/evn_db_name/${config[evn_db_name]}/" InitDB.tmp
        sed -i -e "s/evn_db_schema/${config[evn_db_schema]}/" InitDB.tmp
        sed -i -e "s/evn_db_group/${config[evn_db_group]}/" InitDB.tmp
        sed -i -e "s/evn_db_user/${config[evn_db_user]}/" InitDB.tmp
        sed -i -e "s/evn_db_pw/${config[evn_db_pw]}/" InitDB.tmp
        mv InitDB.tmp Init_${config[evn_db_name]}.sql

        echo "Pour (re)créer la base de données, exécutez la commande suivante depuis le compte postgres"
        echo "$ psql -f $(pwd)/Init_${config[evn_db_name]}.sql"
    ;;

	download)
		echo "Téléchargement depuis le site : ${config[evn_site]} à $(date)"
		# Remove previous downloaded files
		echo "Suppression des fichiers précédents"
		rm -f ~/${config[evn_file_store]}/*.json

		# Download from biolovision and store in json
		php ExportJson.php \
			--site=${config[evn_site]} \
			--user_email=${config[evn_user_email]} \
			--user_pw=${config[evn_user_pw]} \
			--consumer_key=${config[evn_consumer_key]} \
			--consumer_secret=${config[evn_consumer_secret]} \
			--file_store=${config[evn_file_store]} \
			--logging=${config[evn_logging]}

		echo "Fin de l'export à $(date)"
 	;;

	store)
		echo "Chargement des fichiers json dans la base ${config[evn_db_name]} à $(date)"
		php ChargePsql.php \
            --db_host=${config[evn_db_host]} \
            --db_port=${config[evn_db_port]} \
            --db_name=${config[evn_db_name]} \
            --db_schema=${config[evn_db_schema]} \
			--db_user=${config[evn_db_user]} \
			--db_pw=${config[evn_db_pw]}\
			--file_store=${config[evn_file_store]} \
			--logging=${config[evn_logging]}

		echo "Finalisation de la base de données"
		psql -f ChargePsql.sql "dbname=${config[evn_db_name]} options=--search_path=${config[evn_db_schema]},public"

		echo "Fin du chargement à $(date)"

 	;;
   *)
	echo "Usage: $SCRIPTNAME {config|init|download|store}" >&2
 	;;
esac

exit 0
