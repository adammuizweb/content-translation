# Article Translation Progress

Default source locale is English. For every migrated article:

1. Rewrite the source `posts` title, slug, and content in English.
2. Store the original Indonesian title, slug, and content in a published `id` translation.
3. Create a published German (`de`) translation with its own localized title and slug.
4. Preserve HTML structure, code, media URLs, and links unless a localized internal URL exists.
5. Verify all three routes and the language switcher before marking the article complete.

## Batch 1

Process the five newest published articles, in this order:

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 215 | `kepemilikan-digital-mengapa-file-pribadi-tidak-cukup-hanya-disimpan-di-cloud` | Complete |
| 214 | `tranlsateee` | Excluded: switcher test article with user-authored translation |
| 213 | `vendor-risk-management-untuk-organisasi-kecil-menjaga-risiko-yang-datang-dari-pihak-ketiga` | Complete |
| 207 | `incident-response-plan-untuk-organisasi-kecil-saat-insiden-datang-jangan-mulai-dari-panik` | Complete |
| 206 | `business-continuity-plan-untuk-organisasi-kecil-tetap-berjalan-saat-hal-tak-terduga-datang` | Complete |
| 205 | `risk-assessment-keamanan-informasi-cara-memetakan-risiko-sebelum-menjadi-insiden` | Complete: replacement for post 214 |

When the user says `lanjut`, select the next five published articles that do not have both published `id` and `de` translations, ordered by `posts.created_at DESC`.

## Batch 2

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 204 | `cis-controls-v8-untuk-organisasi-kecil-memulai-keamanan-dari-hal-yang-paling-berdampak` | Complete |
| 203 | `phishing-dan-social-engineering-cara-kerja-serangan-manusia-dan-cara-melindungi-diri` | Complete |
| 202 | `monitoring-server-untuk-home-server-setup-uptime-kuma-dan-netdata-agar-tidak-ketinggalan-masalah` | Complete |
| 201 | `nist-cybersecurity-framework-panduan-praktis-menerapkan-5-fungsi-utama-untuk-organisasi-kecil` | Complete |
| 200 | `bug-bounty-untuk-pemula-tips-memulai-perjalanan-menemukan-celah-dengan-etika` | Complete |

## Batch 3

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 199 | `ketidaksempurnaan-digital-mengapa-kita-tak-perlu-memperbaiki-semuanya` | Complete |
| 198 | `warisan-digital-apa-yang-tertinggal-saat-akun-akun-kita-berhenti-aktif` | Complete |
| 197 | `tmux-workflow-mengelola-session-terminal-seperti-mengatur-meja-kerja` | Complete |
| 196 | `owasp-top-10-2025-10-risiko-keamanan-web-yang-wajib-kamu-pahami` | Complete |
| 195 | `neovim-setup-dari-nol-dari-terminal-biasa-jadi-code-editor-andalan` | Complete |

## Batch 4

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 194 | `ai-agent-dari-chatbot-biasa-ke-sistem-yang-bisa-berbuat-sesuatu` | Complete |
| 193 | `prompt-engineering-untuk-llm-lokal-dari-prompt-biasa-ke-hasil-yang-lebih-tepat` | Complete |
| 192 | `threat-modeling-untuk-pemula-belajar-melihat-risiko-sebelum-celah-menjadi-masalah` | Complete |
| 191 | `membaca-cve-dengan-benar-dari-nomor-kerentanan-sampai-keputusan-patch` | Complete |
| 190 | `restful-api-vs-graphql-memilih-pintu-yang-tepat-untuk-data-aplikasi` | Complete |

## Batch 5

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 189 | `ruang-kosong-di-era-ai-mengapa-tidak-semua-hal-perlu-dijawab-seketika` | Complete |
| 188 | `merawat-alat-digital-mengapa-tidak-semua-hal-harus-diganti-saat-ada-yang-baru` | Complete |
| 187 | `storage-management-untuk-home-server-memahami-zfs-lvm-dan-raid-software` | Complete |
| 186 | `container-security-untuk-home-server-dari-docker-rootless-sampai-seccomp` | Complete |
| 185 | `websocket-dari-polling-ke-koneksi-langsung-kenapa-aplikasi-real-time-butuh-protokol-ini` | Complete |

## Batch 6

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 184 | `digital-sabbath-mengapa-aku-sengaja-mematikan-layar` | Complete |
| 183 | `log-management-untuk-home-server-mengumpulkan-log-dari-journald-rsyslog-sampai-loki` | Complete |
| 182 | `rag-retrieval-augmented-generation-mengapa-llm-butuh-buku-referensi-sebelum-menjawab` | Complete |
| 181 | `docker-compose-untuk-home-server-dari-satu-file-yaml-ke-layanan-yang-berjalan-rapi` | Complete |
| 180 | `vpn-self-hosted-untuk-home-server-wireguard-vs-tailscale-pilih-yang-mana` | Complete |

## Batch 7

| Post ID | Current source slug | Status |
| --- | --- | --- |
| 179 | `kelelahan-digital-mengenali-tanda-tanda-burnout-di-era-selalu-terhubung` | Pending |
| 178 | `fomo-digital-kapan-waktu-yang-tepat-untuk-log-off-dari-arus-informasi` | Pending |
| 177 | `stoisme-digital-menemukan-ketenangan-di-antara-notifikasi-ekspektasi-dan-ketidakpastian` | Pending |
| 176 | `buffer-overflow-penjelasan-sederhana-untuk-pemula-yang-mau-belajar-cyber-security` | Pending |
| 175 | `backup-otomatis-untuk-home-server-restic-vs-borgbackup-mana-yang-pas-untuk-kamu` | Pending |
