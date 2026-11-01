Civi Pulse AI - datasets folder
===============================

NO DATASET HAS BEEN SELECTED OR ADDED YET.

  raw/        data exactly as received (never edited). Git-ignored.
  processed/  cleaned, de-identified, split data used by the scripts.
              Git-ignored.

Expected layout of processed/ (checked by the scripts in ../scripts/):

  image_classification/{train,val,test}/<category>/<image_id>.jpg
  image_detection/data.yaml, images/{split}/, labels/{split}/
  text/{train,val,test}.csv          report_id,description,category,language,severity
  spam/{train,val,test}.csv          report_id,description,is_spam,spam_type
  severity/{train,val,test}.csv      report_id,description,image,severity
  consistency/{train,val,test}.csv   pair_id,image,description,is_consistent,pair_type
  duplicate/{train,val,test}.csv     report_a,report_b,is_duplicate,...
  sri_lanka_eval/                    evaluation only - never train on it

Full requirements, labelling rules, privacy rules and recommended sizes:
  ../PROJECT_DATASET_REQUIREMENTS.txt

Never commit data to git. Remove faces, number plates, phone numbers, names,
addresses and EXIF metadata before data enters processed/.
