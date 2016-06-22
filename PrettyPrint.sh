#!/bin/bash

for s in ~/json/*.json 
do
   f=${s##*/}
   if [ "$1" != "" ]; then
      echo "Pretty Printing to json2/$f"
	  cat ~/json/$f | json_reformat > ~/json2/$f
      # python -m json.tool ~/json/$f > ~/json2/$f
   else
      echo "Pretty Printing to json2/$f.bz2"
	  cat ~/json/$f | json_reformat | bzip2 -9 > ~/json2/$f.bz2
      # python -m json.tool ~/json/$f | bzip2 -9 > ~/json2/$f.bz2
   fi

done
