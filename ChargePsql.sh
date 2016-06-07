#!/bin/bash
#
# Analyze dowloaded json and load in postgres
# 
php ChargePsql.php \
	--db_name faune_xxx \
	--db_user role_db \
	--db_pw mot_de_passe_db \
	--file_store=json \
	--logging=INFO
