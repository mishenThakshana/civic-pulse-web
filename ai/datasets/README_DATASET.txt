Civi Pulse AI - datasets folder
===============================

DATASETS IN USE (rebuild with scripts/data_prep/, see "Rebuilding" below)

  Source                               Licence / basis            Used for
  -----------------------------------  -------------------------  --------------------------------
  Road Issues Detection Dataset         CC0-1.0                    image: pothole, road_damage,
  (Programmer-RD-AI, Hugging Face)                                garbage, damaged_sign,
                                                                  damaged_public_property
  FixMyStreet public reports           Public reports read via    text (all 10 categories),
  (mySociety, Open311 API v2;          the Open311 API; used for  image (all 10 categories),
  106 sampled days, 2024-2026)         model training only, not   spam (legitimate class)
                                       redistributed (UK CDPA
                                       s.29A text and data mining
                                       for non-commercial research)
  UCI SMS Spam Collection              CC BY 4.0                  spam (promotional + irrelevant)
  (Almeida and Hidalgo, 2011)
  Wikimedia Commons, 24 photos         CC0 / CC BY / CC BY-SA     external test set ONLY
  (listed in processed/external_eval/                              (never trained or tuned on)
  manifest.csv)

  Category labels for FixMyStreet come from the council category the citizen
  chose, mapped to the CivicPulse taxonomy by scripts/data_prep/fms_mapping.py.
  fallen_tree additionally requires the description to say the tree has fallen.
  All labelled text is English: no public labelled Sinhala or Tamil civic
  complaint dataset exists, so Sinhala/Tamil category accuracy is NOT measured.
  No source carries real severity labels; severity is "unlabelled" and no
  severity model is trained.

Rebuilding (from ai/):
  python scripts/data_prep/build_datasets.py text      # text/ and spam/
  python scripts/data_prep/build_datasets.py images    # image_classification/ (+ manifest.csv)
  python scripts/data_prep/external_eval_set.py        # external_eval/
  Raw downloads go to raw/road_issues_hf/, raw/fixmystreet/days/, raw/sms_spam/.

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
  external_eval/                     evaluation only - never train on it

Full requirements, labelling rules, privacy rules and recommended sizes:
  ../PROJECT_DATASET_REQUIREMENTS.txt

Never commit data to git. Remove faces, number plates, phone numbers, names,
addresses and EXIF metadata before data enters processed/.
