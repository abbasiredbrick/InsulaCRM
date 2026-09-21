# Importing PM Availability Lists

Keystone can import the availability sheets that property-management companies
(AMS, Relevate, Bloom, RDK, …) share by Excel, CSV, PDF or email. Each source
keeps its own column mapping, so a refreshed sheet is a one-click re-import:
existing units update in place, new units are added, and units that leave the
sheet are reconciled automatically.

In the app: **Settings → Inventory Sources** (Import Guide / Sample CSV buttons
are on that screen).

## 1. Prepare the file

- One unit per row, with a header row of column names (auto-detected).
- Never merge cells or add totals/summary rows.
- Keep unit number, rent and status in their own columns.
- Rent and fees must be plain numbers (`125000`, not `AED 125k`).
- If a sheet mixes buildings, include a **Building** column, or set a
  **Default building** on the source and import one building per file.
- Save as **CSV UTF-8** (`Excel: File → Save As → CSV UTF-8`), or upload the
  original `.xlsx`, or paste the text straight from the email.

## 2. Standard columns

| Column header      | Maps to         | Example             | Notes                                   |
| ------------------ | --------------- | ------------------- | --------------------------------------- |
| Unit No            | `unit_no`       | 1201                | Required — identifies the unit          |
| Building           | `building`      | Burj Al Shams       | Optional if the source has a default    |
| Community          | `community`     | Al Reem Island      |                                         |
| Unit Type          | `features`      | 2 BHK, 3 BHK + M    | Bedrooms are read from here too         |
| Area (Sqft)        | `square_footage`| 1121                | Use `Area (Sqm)` for square metres      |
| Bedrooms           | `bedrooms`      | 2                   |                                         |
| Rent               | `rent`          | 100000              | Annual rent in AED                      |
| Deposit            | `deposit`       | 5000                | Blank → source default                  |
| Admin Fee          | `admin_fee`     | 1050                |                                         |
| Tawtheeq Fee       | `tawtheeq`      | 150                 |                                         |
| Status             | `status`        | Available for viewing | See status words below                |
| Balcony            | `balcony`       | Yes / No            |                                         |
| View               | `view`          | Sea View            |                                         |
| Available From     | `available_from`| 26 Sep 2026         | Date an Upcoming unit becomes available |
| Parking            | `parking`       | 1                   |                                         |
| Amenities          | `amenities`     | Gym, Pool           |                                         |
| Remarks            | `remarks`       | Commission 5%       | Commission notes are captured here      |

Column names do not have to match exactly — common synonyms are detected.
Anything you map explicitly on the source always wins.

## 3. Status words

| Text in the sheet                                           | Inventory status |
| ----------------------------------------------------------- | ---------------- |
| Available, Available for viewing, Vacant, Open for viewing  | Ready to List    |
| Upcoming, Up-coming                                         | Upcoming (with the Available From date) |
| Under offer, Reserved, Booked, On hold                      | Reserved         |
| Rented, Leased, Let                                         | Leased           |
| Sold                                                        | Sold             |
| Withdrawn, Off market, Unlisted                             | Unlisted         |

Override any of these on the source screen with lines like
`Under Offer => reserved`.

## 4. Dates, fees and deposit

- Dates are read in many formats: `14 Sep 2026`, `Sep 14, 2026`, `14/09/2026`,
  `14.09.2026`, `2026-09-14`.
- The word `Available` in a date column means the unit is ready now (no date).
- Admin/Tawtheeq fees fall back to the source defaults when the sheet has no
  column for them.
- Deposit can be a fixed default, or a formula: *the higher of AED 5,000 or 5%
  of the annual rent*. Set the deposit percentage and minimum on the source and
  it is computed per unit.

## 5. Importing the refreshed list

1. Open the source → **Re-import** (or **Sync now** for a published URL).
2. Upload the refreshed sheet (or paste text) and preview the parsed rows.
3. Run the import. Units update in place — nothing is duplicated.
4. Units that disappear from the sheet follow the source rule: **mark as leased**
   for rented sheets, or **mark as unlisted** for published links. A *listed*
   unit that disappears from a rented sheet is queued for a decision instead of
   being unlisted, so a paid listing permit is not wasted.
5. If a new sheet has different columns, the importer rebuilds the mapping from
   the header row automatically.

## 6. Sample templates

Use **Sample CSV** to download a spreadsheet with the expected columns and two
example rows — either the blank template or one matching a specific source's
saved mapping. Open it in Excel, replace the examples, save as CSV, and import.
