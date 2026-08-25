# wordpress-help

This repository contains work for the ACPS WordPress site.

## `acps-media-cleanup/` — Unused Media Cleanup plugin

A single-site WordPress plugin that safely finds and removes media library files
(images, PDFs, documents, videos) that are **not used anywhere** on the site, and
lets you clean them up **folder by folder** (FileBird-aware). It is designed to be
very safe: scanning changes nothing, detection errs toward "used", deletion
defaults to reversible Trash, and multiple guard rails are enforced server-side.

See [`acps-media-cleanup/README.md`](acps-media-cleanup/README.md) for full
documentation, installation, and the safe cleanup workflow.

## Site export XML

The `acpswebsite.WordPress.*.xml` files are WordPress (WXR) exports of the ACPS
site, kept here as reference for the site's content, media, and folder structure.
