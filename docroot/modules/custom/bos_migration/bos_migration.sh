#!/bin/bash

function doMigrate() {
    NC='\033[0m' # No Color
    RED='\033[0;31m'
    SECONDS=0
    ERRORS=0

    while true; do
        printf "\n${drush} mim $* --feedback=1000 --limit=10\n" | tee -a ${logfile}
        ${drush} mim $* --feedback=1000 --limit=10 | tee -a ${logfile}
        retVal=$?
        if [ $retVal -eq 0 ]; then break; fi
        ERRORS=$((ERRORS+1))
    done
    echo "ExitCode: ${retVal}"

    ${drush} ms ${1} | tee -a ${logfile}

    if [ $ERRORS -ne 0 ]; then
        printf "${RED}Migrate command completed with Errors.${NC}\n"  | tee -a ${logfile}
    fi

    printf " -> Run time: " | tee -a ${logfile}
    if (( $SECONDS > 3600 )); then
        let "hours=SECONDS/3600"
        text="hour"
        if (( $hours > 1 )); then text="hours"; fi
        printf "$hours $text, " | tee -a ${logfile}
    fi
    if (( $SECONDS > 60 )); then
        let "minutes=(SECONDS%3600)/60"
        text="minute"
        if (( $minutes > 1 )); then text="minutes"; fi
        printf "$minutes $text and " | tee -a ${logfile}
    fi
    let "seconds=(SECONDS%3600)%60"
    text="second"
    if (( $seconds > 1 )); then text="seconds"; fi
    printf "$seconds $text.${NC}\n" | tee -a ${logfile}
}

function doExecPHP() {
    if [ -d "/mnt/gfs" ]; then
        ${drush} php-eval $*  | tee -a ${logfile}
    else
        lando ssh -c  "/app/vendor/bin/drush php-eval $*"  | tee -a ${logfile}
    fi
}

function restoreDB() {
    # Remove old database and restore baseline
    printf "RESTORING DB ${1}\n" | tee -a ${logfile}
    ${drush} sql:drop --database=default -y  | tee -a ${logfile}
    if [ -d "/mnt/gfs" ]; then
        ${drush} sql:cli -y --database=default < ${1}  | tee -a ${logfile}
    else
        lando ssh -c  "/app/vendor/bin/drush sql:cli -y  < ${1}" | tee -a ${logfile}
    fi

    ## Sync current config with the database.
    ${drush} cim -y  | tee -a ${logfile}
    ${drush} cim --partial --source=modules/custom/bos_migration/config/install/ -y  | tee -a ${logfile}

    # Ensure the needed modules are enabled.
    ${drush} cdel views.view.migrate_taxonomy
    ${drush} cdel views.view.migrate_paragraphs
    ${drush} en migrate,migrate_upgrade,migrate_drupal,migrate_drupal_ui,field_group_migrate,migrate_plus,migrate_tools,bos_migration -y  | tee -a ${logfile}

    # rebuild the migration configs.
    ${drush} updb -y  | tee -a ${logfile}
    ${drush} entup -y  | tee -a ${logfile}
    doExecPHP "node_access_rebuild();"
    printf " -> RESTORED.\n" | tee -a ${logfile}

    ${drush} cr  | tee -a ${logfile}
    ${drush} ms  | tee -a ${logfile}
}

function dumpDB() {
    # Dump current DB.
    printf "DUMPING DB ${1}\n" | tee -a ${logfile}
    if [ -d "/mnt/gfs" ]; then
        ${drush} sql:dump -y --database=default > ${1}
    else
        lando ssh -c  "/app/vendor/bin/drush sql:dump -y > ${1}"
    fi
    printf " -> DUMPED.\n" | tee -a ${logfile}
}

if [ -d "/mnt/gfs" ]; then
    dbpath="/mnt/gfs/bostond8dev/backups/on-demand"
    logfile="/mnt/gfs/bostond8dev/sites/default/files/bos_migration.log"
    drush="drush"
    printf "Running in REMOTE mode:\n"| tee ${logfile}
else
#    dbpath=" ~/sources/boston.gov-d8/dump/migration"
    dbpath=" /app/dump/migration"
    logfile="./bos_migration.log"
    drush="lando drush"
    printf "Running in LOCAL DOCKER mode:\n"| tee ${logfile}
fi

running=0

# Set migration variables.
${drush} sset "bos_migration.fileOps" "copy"
${drush} sset "bos_migration.dest_file_exists" "use\ existing"
${drush} sset "bos_migration.dest_file_exists_ext" "skip"
${drush} sset "bos_migration.remoteSource" "https://www.boston.gov/"
${drush} sset "bos_migration.active" "1"

## Migrate files first.
if [ "$1" == "reset" ]; then
    running=1
    restoreDB "${dbpath}/migration_clean_reset.sql"
    doMigrate --tag="bos:initial:0" --force                 # 31 mins
    dumpDB ${dbpath}/migration_clean_with_files.sql
fi

## Perform the lowest level safe-dependencies.
if [ "$1" == "files" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "files" ]; then restoreDB "${dbpath}/migration_clean_with_files.sql"; fi
    doMigrate --tag="bos:initial:1" --force                 # 7 mins
    dumpDB ${dbpath}/migration_clean_with_prereq.sql
fi

# Taxonomies first.
if [ "$1" == "prereq" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "prereq" ]; then restoreDB "${dbpath}/migration_clean_with_prereq.sql"; fi
    doMigrate d7_taxonomy_vocabulary -q --force             # 6 secs
    doExecPHP "\Drupal\bos_migration\MigrationFixes::fixTaxonomyVocabulary();"
    doMigrate --tag="bos:taxonomy:1" --force                # 30 secs
    doMigrate --tag="bos:taxonomy:2" --force                # 12 sec
    dumpDB ${dbpath}/migration_clean_after_taxonomy.sql
fi

if [ "$1" == "taxonomy" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "taxonomy" ]; then restoreDB "${dbpath}/migration_clean_after_taxonomy.sql"; fi
    doMigrate --tag="bos:paragraph:1" --force               # 27 mins
    doMigrate --tag="bos:paragraph:2" --force               # 17 mins
    doMigrate --tag="bos:paragraph:3" --force               # 14 mins
    doMigrate --tag="bos:paragraph:4" --force               # 1 min 15 secs
    dumpDB ${dbpath}/migration_clean_after_all_paragraphs.sql
fi

## Do these last b/c creates new paragraphs that might steal existing paragraph entity & revision id's.
if [ "$1" == "paragraphs" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "paragraphs" ]; then restoreDB "${dbpath}/migration_clean_after_all_paragraphs.sql"; fi
    doMigrate --group=bos_field_collection --force          # 4 mins
    dumpDB ${dbpath}/migration_clean_after_field_collection.sql
fi

# Redo paragraphs which required field_collections to be migrated to para's first.
if [ "$1" == "field_collection" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "field_collection" ]; then restoreDB "${dbpath}/migration_clean_after_field_collection.sql"; fi
    doMigrate --tag="bos:paragraph:10" --force --update      # 3 min 15 secs
    # Fix the listview component to match new view names and displays.
    doExecPHP "\Drupal\bos_migration\MigrationFixes::fixListViewField();"
    dumpDB ${dbpath}/migration_clean_after_para_update_1.sql
fi

# Migrate nodes in sequence.
if [ "$1" == "update1" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "update1" ]; then restoreDB "${dbpath}/migration_clean_after_para_update_1.sql"; fi
    doMigrate --tag="bos:node:1" --force
    doMigrate --tag="bos:node:2" --force                    # 14 mins
    doMigrate --tag="bos:node:3" --force                    # 52 mins
    doMigrate --tag="bos:node:4" --force                    # 9 secs
    dumpDB ${dbpath}/migration_clean_after_nodes.sql
fi

# Redo para's which have nodes in fields.
if [ "$1" == "nodes" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "nodes" ]; then restoreDB "${dbpath}/migration_clean_after_nodes.sql"; fi
    doMigrate --tag="bos:paragraph:99" --force --update     # 5 mins
    dumpDB ${dbpath}/migration_clean_after_para_update_2.sql
fi

# Now do the node revisions (nodes and all paras must be done first)
if [ "$1" == "update2" ] || [ $running -eq 1 ]; then
    running=1
    if [ "$1" == "update2" ]; then restoreDB "${dbpath}/migration_clean_after_para_update_2.sql"; fi
    doMigrate --tag="bos:node_revision:1" --force           # 2h 42 mins
    doMigrate --tag="bos:node_revision:2" --force           # 8h 50 mins
    doMigrate --tag="bos:node_revision:3" --force           # 1hr 43 mins
    doMigrate --tag="bos:node_revision:4" --force           # 30 sec
    dumpDB ${dbpath}/migration_clean_after_node_revision.sql
fi

## Finish off.
if [ "$1" == "node_revision" ] || [ $running -eq 1 ]; then
    running=1
#    if [ "$1" == "node_revision" ]; then restoreDB "${dbpath}/migration_clean_after_node_revision.sql"; fi
    doMigrate d7_menu_links,d7_menu --force
    dumpDB ${dbpath}/migration_clean_after_menus.sql
fi

if [ "$1" == "final" ]; then
    running=1
    restoreDB "${dbpath}/migration_clean_after_menus.sql"
fi

if [ $running -eq 0 ]; then
    printf "Bad script parameter\nOptions are:\n  reset, files, rereq, taxonomy, paragraphs, field_collection, update1, nodes, update2, node_revision, menus, final"
    exit 1
fi

## Ensure everything is updated.
${drush} entup -y  | tee -a ${logfile}
doExecPHP "node_access_rebuild();"
dumpDB ${dbpath}/migration_FINAL.sql

${drush} sdel "bos_migration.active"
${drush} sset "bos_migration.fileOps" "copy"
