function autoNoteVTO(e) {
  var mainSheetName = "VTO";
  var triggerColumn = 18; // Column R
  var triggerValue = "fire";

  var range = e.range;
  var sheet = range.getSheet();

  if (
    sheet.getName() === mainSheetName &&
    range.getColumn() === triggerColumn
  ) {
    var rowNumber = range.getRow();
    var value = e.value;

    if (
      value &&
      typeof value === "string" &&
      value.toLowerCase() === triggerValue.toLowerCase()
    ) {
      addNoteToNotefile(rowNumber);
      range.clearContent();
    }
  }
}

function checkForFireTriggersVTO() {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName("VTO");
    var triggerColumn = 18; // Column P

    if (!sheet) return;

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    var dataRange = sheet.getRange(1, 1, lastRow, 20);
    var data = dataRange.getValues();

    for (var i = 1; i < data.length; i++) {
      var rowNumber = i + 1;
      var fireValue = data[i][17]; // Column P is index 15 (0-based)

      if (fireValue && fireValue.toString().toLowerCase() === "fire") {
        addNoteToNotefile(rowNumber);
        sheet.getRange(rowNumber, triggerColumn).clearContent();
      }
    }
  } catch (error) {
    Logger.log("Error in checkForFireTriggersVTO: " + error.toString());
  }
}

function setupAutoFireTriggerVTO() {
  // Remove any existing checkForFireTriggersVTO triggers first
  var triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(function (trigger) {
    if (trigger.getHandlerFunction() === "checkForFireTriggersVTO") {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  // Create new time-based trigger to run every 5 minutes
  ScriptApp.newTrigger("checkForFireTriggersVTO")
    .timeBased()
    .everyMinutes(1)
    .create();

  Browser.msgBox(
    "Success",
    "Auto-fire trigger setup complete! checkForFireTriggersVTO will run every 1 minutes.",
    Browser.Buttons.OK,
  );
}

function addNoteToNotefile(rowNumber) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var mainSheet = ss.getSheetByName("VTO");

  var externalSpreadsheetId = "1XeyCSc3_cgMWXev2b-2XXZkmbzw6gdrigU-va1jx920";
  var externalSheetName = "AUG";

  var externalSs;
  try {
    externalSs = SpreadsheetApp.openById(externalSpreadsheetId);
  } catch (e) {
    Browser.msgBox(
      "Error",
      "Could not open the external spreadsheet. Please check the ID and your permissions. Error: " +
        e.message,
      Browser.Buttons.OK,
    );
    return;
  }

  var schedfileSheet = externalSs.getSheetByName(externalSheetName);
  if (!schedfileSheet) {
    Browser.msgBox(
      "Error",
      "The sheet named '" +
        externalSheetName +
        "' was not found in the external spreadsheet.",
      Browser.Buttons.OK,
    );
    return;
  }

  var VTOType = mainSheet.getRange("O" + rowNumber).getValue();
  var VTOTime = mainSheet.getRange("L" + rowNumber).getDisplayValue();
  var name = mainSheet.getRange("D" + rowNumber).getDisplayValue();
  var dateStrMain = mainSheet.getRange("A" + rowNumber).getDisplayValue();
  var originalShift = mainSheet.getRange("H" + rowNumber).getValue();
  var Coverage = mainSheet.getRange("I" + rowNumber).getDisplayValue();
  var CoverageType = mainSheet.getRange("J" + rowNumber).getValue();
  var MinsWorked = mainSheet.getRange("M" + rowNumber).getValue();
  var VTOMins = mainSheet.getRange("N" + rowNumber).getValue();
  var Approved_by = mainSheet.getRange("P" + rowNumber).getValue();
  var sltDuty = mainSheet.getRange("Q" + rowNumber).getValue();

  if (!name || !dateStrMain) {
    Browser.msgBox(
      "Data Missing",
      "Please ensure 'Name' (Column D) and 'Date' (Column A) are filled in row " +
        rowNumber +
        ".",
      Browser.Buttons.OK,
    );
    return;
  }

  var mainDate = new Date(dateStrMain);
  var headerDates = schedfileSheet.getRange("1:1").getValues()[0];
  var dateColumn = -1;

  for (var i = 0; i < headerDates.length; i++) {
    var schedfileDateValue = headerDates[i];
    if (schedfileDateValue instanceof Date) {
      var formattedSchedfileDate = Utilities.formatDate(
        schedfileDateValue,
        externalSs.getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );
      var formattedMainDate = Utilities.formatDate(
        mainDate,
        externalSs.getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );

      if (formattedSchedfileDate === formattedMainDate) {
        dateColumn = i + 1;
        break;
      }
    }
  }

  if (dateColumn === -1) {
    Browser.msgBox(
      "Date Not Found",
      "The date '" +
        dateStrMain +
        "' was not found in the header row of '" +
        externalSheetName +
        "'.",
      Browser.Buttons.OK,
    );
    return;
  }

  var empId = mainSheet.getRange("C" + rowNumber).getValue();
  var lastRow = schedfileSheet.getLastRow();
  var eidsInSchedfile = schedfileSheet.getRange("A1:A" + lastRow).getValues();
  var nameRow = -1;

  for (var j = 0; j < eidsInSchedfile.length; j++) {
    if (
      eidsInSchedfile[j][0] &&
      eidsInSchedfile[j][0].toString().trim() === empId.toString().trim()
    ) {
      nameRow = j + 1;
      break;
    }
  }

  if (nameRow === -1) {
    Browser.msgBox(
      "Employee ID Not Found",
      "The Employee ID '" +
        empId +
        "' was not found in Column A of '" +
        externalSheetName +
        "'.",
      Browser.Buttons.OK,
    );
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);
  var vtoAgentColor = targetCell.getBackground(); // capture BEFORE overwrite, used to color the coverer's cell

  var comment =
    "VTO Type: " +
    VTOType +
    "\nVTO time: " +
    VTOTime +
    "\nOriginal Shift: " +
    originalShift +
    "\nCover By: " +
    Coverage +
    " " +
    CoverageType +
    "\nMinutes Worked: " +
    MinsWorked +
    " Mins" +
    "\nVTO minutes: " +
    VTOMins +
    " Mins" +
    "\n" +
    "\n" +
    "\nApproved by: " +
    Approved_by +
    "\n" +
    "\n" +
    sltDuty;

  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    // Append instead of overwrite so a late/undertime note written earlier isn't wiped out
    if (existingNote.indexOf(comment.trim()) === -1) {
      targetCell.setNote(existingNote.trim() + "\n\n" + comment);
    }
  } else {
    targetCell.setNote(comment);
  }
  // Set cell value based on MinsWorked
  if (MinsWorked === 0) {
    targetCell.setValue("VTO - WD");
  } else {
    targetCell.setValue("VTO");
  }
  targetCell.setBackground("#00FFFF");

  // Auto-note for the person who covered (VTO)
  if (Coverage && Coverage.toString().trim() !== "") {
    addCoverageNote(
      schedfileSheet,
      headerDates,
      mainDate,
      name,
      originalShift,
      Coverage,
      CoverageType,
      "",
      sltDuty,
      "VTO",
      vtoAgentColor,
    );
  }

  Browser.msgBox(
    "Success!",
    "Note added to cell " +
      targetCell.getA1Notation() +
      " in '" +
      externalSheetName +
      "' for " +
      name +
      " on " +
      dateStrMain +
      ".",
    Browser.Buttons.OK,
  );
}

// Helper function to write coverage note on covering employee's cell in Schedfile

function addCoverageNote(
  schedfileSheet,
  headerDates,
  mainDate,
  absentEmployeeName,
  originalShift,
  coveringName,
  coverageType,
  coverageDetails,
  sltDuty,
  statusType,
  vtoAgentColor,
) {
  var lastRow = schedfileSheet.getLastRow();
  if (lastRow < 1) return;
  var namesInSchedfile = schedfileSheet.getRange("L1:L" + lastRow).getValues();
  var nameRow = -1;

  // Extract only the name part (strip trailing times, parentheses, or comments)
  var cleanCoveringName = coveringName
    ? coveringName
        .toString()
        .split(/\b\d|[(]/)[0]
        .trim()
    : "";

  for (var j = 0; j < namesInSchedfile.length; j++) {
    if (
      namesInSchedfile[j][0] &&
      cleanName(namesInSchedfile[j][0]) === cleanName(cleanCoveringName)
    ) {
      nameRow = j + 1;
      break;
    }
  }

  if (nameRow === -1) {
    Logger.log(
      "Covering employee '" +
        cleanCoveringName +
        "' (from input '" +
        coveringName +
        "') not found in Column L.",
    );
    return;
  }

  // 1. Extract time range (e.g. "12:00 AM - 5:00 AM" or "11PM - 3AM") from coveringName or coverageDetails if available
  var timeRegex =
    /\b\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*-\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)/i;
  var coverageShift = "";

  if (coveringName) {
    var timeMatchName = coveringName.toString().match(timeRegex);
    if (timeMatchName) {
      coverageShift = timeMatchName[0];
    }
  }

  if (!coverageShift && coverageDetails) {
    var timeMatchDetails = coverageDetails.toString().match(timeRegex);
    if (timeMatchDetails) {
      coverageShift = timeMatchDetails[0];
    }
  }

  // 2. Calculate if the coverage falls on the next calendar day (crossover shift)
  var finalDate = new Date(mainDate);
  if (coverageShift !== "" && originalShift) {
    var startHourMatch = coverageShift.match(
      /^\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?/i,
    );
    var absentStartHourMatch = originalShift
      .toString()
      .match(/^\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?/i);
    if (startHourMatch && absentStartHourMatch) {
      var startAmPm = startHourMatch[3] ? startHourMatch[3].toUpperCase() : "";
      var absentStartAmPm = absentStartHourMatch[3]
        ? absentStartHourMatch[3].toUpperCase()
        : "";
      // If absent shift starts in PM (e.g. 11PM) and coverage shift starts in AM (e.g. 2AM)
      if (absentStartAmPm === "PM" && startAmPm === "AM") {
        finalDate.setDate(finalDate.getDate() + 1);
      }
    }
  }

  // 3. Find date column index for finalDate
  var dateColumn = -1;
  for (var i = 0; i < headerDates.length; i++) {
    var schedfileDateValue = headerDates[i];
    if (schedfileDateValue instanceof Date) {
      var formattedSchedfileDate = Utilities.formatDate(
        schedfileDateValue,
        schedfileSheet.getParent().getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );
      var formattedFinalDate = Utilities.formatDate(
        finalDate,
        schedfileSheet.getParent().getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );

      if (formattedSchedfileDate === formattedFinalDate) {
        dateColumn = i + 1;
        break;
      }
    }
  }

  if (dateColumn === -1) {
    Logger.log("Date Column not found for coverage date: " + finalDate);
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);

  // 4. Get original shift of the covering employee (read from their cell before we overwrite/modify anything)
  // Keep rawCellText untouched (used later for DSOT stacking) -- coveringOriginalShift below
  // may get fallback-substituted for note-display purposes, and stacking must never treat an
  // already-stacked multi-line cell as if it were a single "own shift" (that caused unbounded
  // line growth on repeat fires).
  var rawCellText = targetCell.getValue();
  rawCellText = rawCellText ? rawCellText.toString().trim() : "";
  var coveringOriginalShift = rawCellText;
  var coverCellWasBlank = !coveringOriginalShift;

  var isDSOT =
    coverageType && coverageType.toString().toUpperCase().indexOf("DSOT") !== -1;

  // DSOT: the time typed alongside the coverer's name (e.g. "Juan (11:00 PM - 3:00 AM)") is
  // the SPECIFIC SEGMENT of the VTO'd employee's shift this coverer is handling -- not the
  // coverer's own shift (matters when multiple coverers split one shift). Falls back to the
  // VTO'd employee's full shift when no segment was typed.
  var dsotCoverageShift = coverageShift
    ? coverageShift
    : originalShift
      ? originalShift.toString().trim()
      : "";

  // Coverer's schedule cell is blank (e.g. they're on RDOT/DSOT that day). For non-DSOT, fall
  // back to the time typed alongside their name, and if even that's missing, copy the VTO'd
  // employee's shift as a single line. For DSOT, leave it blank -- the typed time is the
  // coverage segment (above), not their own shift.
  var usedFallbackShift = false;
  if (!coveringOriginalShift && !isDSOT) {
    if (coverageShift) {
      coveringOriginalShift = coverageShift;
    } else if (originalShift) {
      coveringOriginalShift = originalShift.toString().trim();
      usedFallbackShift = true;
    }
  }

  var comment = "";
  if (isDSOT) {
    comment =
      "COVERAGE: " +
      absentEmployeeName +
      " (" +
      statusType +
      ")" +
      "\nORIGINAL SHIFT: " +
      coveringOriginalShift +
      "\nCOVERAGE SHIFT: " +
      dsotCoverageShift +
      "\nCOVERAGE TYPE: " +
      coverageType +
      "\n" +
      "\n" +
      sltDuty;
  } else if (statusType === "VTO") {
    comment =
      "COVERAGE: " +
      absentEmployeeName +
      " (VTO)" +
      "\nSHIFT: " +
      coveringOriginalShift +
      "\nCOVERAGE TYPE: " +
      coverageType +
      "\n" +
      "\n" +
      sltDuty;
  } else {
    // Absent
    if (coverageShift !== "") {
      // With interval (Half coverage)
      comment =
        "COVERAGE: " +
        absentEmployeeName +
        " (ABSENT)" +
        "\nORIGINAL SHIFT: " +
        coveringOriginalShift +
        "\nCOVERAGE SHIFT: " +
        coverageShift +
        "\nCOVERAGE TYPE: " +
        coverageType +
        "\n" +
        "\n" +
        sltDuty;
    } else {
      // No interval (Solo/malinis coverage)
      comment =
        "COVERAGE: " +
        absentEmployeeName +
        " (ABSENT)" +
        "\nSHIFT: " +
        coveringOriginalShift +
        "\nCOVERAGE TYPE: " +
        coverageType +
        "\n" +
        "\n" +
        sltDuty;
    }
  }

  // Add this coverage's shift line to whatever's already in the cell, deduped and sorted
  // chronologically. Reuses stackShiftLines/parseStartMinutes from autoNoteAbsent.gs (same GAS
  // project, shared global scope). Uses rawCellText (step 4's untouched read), not
  // coveringOriginalShift, to avoid re-stacking an already-stacked cell on repeat fires.
  var stackedValue;
  if (isDSOT) {
    stackedValue = stackShiftLines(rawCellText, dsotCoverageShift);
  } else if (statusType === "VTO") {
    // VTO coverer is a straight backup taking over the exact same shift, not working an
    // additional/different one -- just copy the VTO'd employee's shift, no stacking.
    stackedValue = originalShift;
  } else if (usedFallbackShift) {
    stackedValue = originalShift;
  } else {
    var ownStart = parseStartMinutes(coveringOriginalShift);
    var coveredStart = parseStartMinutes(originalShift);
    if (ownStart !== -1 && coveredStart !== -1 && coveredStart < ownStart) {
      stackedValue = originalShift + "\n" + coveringOriginalShift;
    } else {
      stackedValue = coveringOriginalShift + "\n" + originalShift;
    }
  }
  targetCell.setValue(stackedValue);

  // DSOT: coverer works their own regular shift elsewhere, so skip copying the VTO'd
  // employee's color -- UNLESS the coverer had no plotted schedule of their own (blank cell,
  // e.g. RDOT/DSOT day), in which case still copy it same as other statuses.
  if (vtoAgentColor && (!isDSOT || coverCellWasBlank)) {
    targetCell.setBackground(vtoAgentColor);
  }

  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    // Check if the exact comment is already present to prevent duplication
    if (existingNote.indexOf(comment.trim()) !== -1) {
      Logger.log("Coverage note already exists on cell. Skipping duplication.");
      return;
    }
    targetCell.setNote(existingNote.trim() + "\n\n" + comment);
  } else {
    targetCell.setNote(comment);
  }
}

// Function to normalize names for safe comparison (ignores double spaces, capitalization, and trailing whitespace)
function cleanName(str) {
  if (!str) return "";
  return str.toString().toLowerCase().replace(/\s+/g, " ").trim();
}
