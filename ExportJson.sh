#!/bin/bash

# Remove previous downloaded files
echo "Suppression des fichiers précédents"
rm -f ~/json/*.json

# Download from biolovision and store in json
php ExportJson.php \
	--site http://www.faune-xxx.org/ \
	--user_email compte_VN \
	--user_pw mot_de_passe_VN \
	--consumer_key cle_API \
	--consumer_secret code_API \
	--file_store=json \
	--logging=INFO
