#!/bin/bash

# Set variables
# USER and SERVICE_NAME should be passed as parameters
USER=$1
SERVICE_NAME=$2
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/snapshots/$USER/$TIMESTAMP/$SERVICE_NAME"

# Create backup directory
mkdir -p $BACKUP_DIR

# List of volumes to backup from docker-compose.yml
VOLUMES=(
  "${SERVICE_NAME}_drupal-root"
)

# Backup each volume
for VOLUME in "${VOLUMES[@]}"; do
  echo "Backing up volume: $VOLUME"
  # Create a backup file for each volume
  docker run --rm \
    -v ${VOLUME}:/source \
    -v ${BACKUP_DIR}:/backup \
    alpine \
    tar -czf /backup/${VOLUME}.tar.gz -C /source .

  # Check if backup was successful
  if [ $? -eq 0 ]; then
    echo "✅ Successfully backed up $VOLUME to $BACKUP_DIR/${VOLUME}.tar.gz"
  else
    echo "❌ Failed to backup $VOLUME"
  fi
done

# Add metadata about the backup
echo "Backup created on $(date)" > $BACKUP_DIR/backup_info.txt
echo "Service: $SERVICE_NAME" >> $BACKUP_DIR/backup_info.txt
echo "Volumes included:" >> $BACKUP_DIR/backup_info.txt
printf "%s\n" "${VOLUMES[@]}" >> $BACKUP_DIR/backup_info.txt

echo "All volumes backed up to $BACKUP_DIR"
