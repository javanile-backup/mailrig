#!/usr/bin/env bash

if [[ -f Procfile ]]; then
  (
    echo "[supervisord]"
    echo "nodaemon=true"
    echo "redirect_stderr=true"
    echo "stdout_logfile=/proc/1/fd/1"
    #echo "logfile=/proc/1/fd/1"
    echo "logfile_maxbytes=50MB"
    echo "logfile_backups=10"
    echo "umask=022"
    echo "user=root"
  ) > supervisord.conf
  lineno=1
  while IFS= read line || [[ -n "${line}" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    lineno=$((lineno + 1))
    [[ -z "${line}" ]] && continue
    [[ "${line::1}" == "#" ]] && continue
    (
      echo "[program:Procfile_${lineno}]"
      echo "command=mailrig --${line}"
      echo "stdout_logfile=Procfile_${lineno}.log"
    ) >> supervisord.conf
  done < Procfile
fi

commands=(task copy copy-sync)
if [[ " ${commands[@]} " =~ " $1 " ]]; then
  set -- mailrig "--${@}"
else
  set -- supervisord "${@}"
fi

exec "${@}"
