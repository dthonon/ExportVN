#!/bin/bash
# find ~/json -name '*.json' -execdir echo $(basename '{}') ';' 
# find ~/json -name '*.json' -execdir python -m json.tool ~/json/'{}' > ~/json2/'{}' ';' 

for s in ~/json/*.json 
do
   f=${s##*/}
   echo "Processing $f"
   python -m json.tool ~/json/$f > ~/json2/$f
done
