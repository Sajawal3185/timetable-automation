
// 1. SUBJECTS LIST
const SUBJECTS = [
  { code: "SEIT-111", name: "Application of Information and Communication Technology", semester: 1 },
  { code: "SEPF-121", name: "Programming Fundamentals", semester: 1 },
  { code: "SECA-117", name: "Calculus and Analytical Geometry", semester: 1 },
  { code: "SEDS-116", name: "Discrete Structures", semester: 2 },
  { code: "SEOO-122", name: "Object Oriented Programming", semester: 2 },
  { code: "SESE-123", name: "Software Engineering", semester: 2 },
  { code: "SEDA-225", name: "Data Structure and Algorithms", semester: 3 },
  { code: "SEOS-226", name: "Operating Systems", semester: 3 },
  { code: "SELA-201", name: "Linear Algebra", semester: 3 },
  { code: "SESD-242", name: "Software Design & Architecture", semester: 4 },
  { code: "SEFM-262", name: "Formal Methods in SE", semester: 4 },
  { code: "SEDB-227", name: "Database Systems", semester: 4 },
  { code: "SEBP-263", name: "Business Process Engineering", semester: 4 },
  { code: "SESR-241", name: "Software Requirements Engineering", semester: 4 },
  { code: "SESC-343", name: "Software Construction & Development", semester: 5 },
  { code: "SEAI-331", name: "Artificial Intelligence", semester: 5 },
  { code: "SEHC-364", name: "Human Computer Interaction", semester: 5 },
  { code: "SECN-228", name: "Computer Networks", semester: 5 },
  { code: "SEPS-303", name: "Probability & Statistics", semester: 5 },
  { code: "SEVP-365", name: "Visual Programming", semester: 6 },
  { code: "SEIS-432", name: "Information Security", semester: 6 },
  { code: "SESQ-344", name: "Software Quality Engineering", semester: 6 },
  { code: "SEWE-363", name: "Web Engineering", semester: 6 },
  { code: "SEMA-366", name: "Mobile App Development", semester: 7 },
  { code: "SESR-448", name: "Software Re-Engineering", semester: 7 },
  { code: "SESP-445", name: "Software Project Management", semester: 7 },
  { code: "SEOR-468", name: "Operation Research", semester: 7 },
  { code: "SEGD-468", name: "Game Development", semester: 8 },
  { code: "SEPI-468", name: "Software Process Improvement", semester: 8 },
  { code: "SEWE-467", name: "Web Engineering II", semester: 8 },
 
];

// 2. SAMPLE PAPERS
// Example records only; replace paths with your actual PDF files.

const papers = [
  {
    id: 1,
    subject: "SEDA-225",
    year: 2024,
    type: "Midterm",

    url: "../papers/sda-mid-a.pdf",
    status: "approved"
  },
  {
    id: 2,
    subject: "SEDA-225",
    year: 2024,
    type: "Midterm",

    url: "../papers/sda-mid-b.pdf",
    status: "approved"
  },
  {
    id: 3,
    subject: "SEDA-225",
    year: 2024,
    type: "Final",

    url: "../papers/sda-final-a.pdf",
    status: "approved"
  }
];

// 3. POPULATE SUBJECT DROPDOWNS

function fillSubjects(id) {
  const dropdown = document.getElementById(id);
  if (!dropdown) return;

  dropdown.replaceChildren();

  dropdown.add(new Option(
    id === "fSubject" ? "All Subjects" : "Select Subject",
    ""
  ));

  SUBJECTS.forEach(subject => {
    dropdown.add(new Option(
      `${subject.code} - ${subject.name}`,
      subject.code
    ));
  });
}

// 4. POPULATE YEAR DROPDOWNS

function fillYears(id, allOption) {
  const dropdown = document.getElementById(id);
  if (!dropdown) return;

  dropdown.replaceChildren();

  dropdown.add(new Option(
    allOption ? "All Years" : "Select Year",
    ""
  ));

  const currentYear = new Date().getFullYear();

  for (let year = currentYear; year >= 2015; year--) {
    dropdown.add(new Option(String(year), String(year)));
  }
}

// 5. DISPLAY PAPERS

function show() {
  const list = document.getElementById("list");
  const empty = document.getElementById("empty");

  if (!list) return;

  const subject = document.getElementById("fSubject")?.value || "";
  const year = document.getElementById("fYear")?.value || "";
  const type = document.getElementById("fType")?.value || "";

  list.replaceChildren();

  const results = papers.filter(paper =>
    paper.status === "approved" &&
    (!subject || paper.subject === subject) &&
    (!year || String(paper.year) === year) &&
    (!type || paper.type === type)
  );

  results.sort((a, b) => b.year - a.year);

  results.forEach(paper => {
    const course = SUBJECTS.find(
      item => item.code === paper.subject
    );

    const card = document.createElement("div");
    card.className = "paper-card";

    const heading = document.createElement("h3");
    heading.textContent = course
      ? `${course.code} - ${course.name}`
      : paper.subject;

    const details = document.createElement("p");
    details.textContent =
      `Year: ${paper.year} | ` +
      `Type: ${paper.type === "Midterm" ? "Mids" : "Final"}` +
      (paper.section ? ` | Section: ${paper.section}` : "");

    const view = document.createElement("a");
    view.href = paper.url;
    view.textContent = "View Paper";
    view.target = "_blank";
    view.rel = "noopener noreferrer";

    const download = document.createElement("a");
    download.href = paper.url;
    download.textContent = "Download";
    download.setAttribute("download", "");

    card.append(heading, details, view, download);
    list.append(card);
  });

  if (empty) {
    empty.hidden = results.length !== 0;
  }
}

// 6. UPLOAD FORM

function setupUpload() {
  const form = document.getElementById("uploadForm");
  if (!form) return;

  form.addEventListener("submit", event => {
    event.preventDefault();

    const subject = document.getElementById("uSubject")?.value;
    const year = document.getElementById("uYear")?.value;
    const type = document.getElementById("uType")?.value;
    const file = document.getElementById("uFile")?.files?.[0];
    const message = document.getElementById("uMsg");

    if (!subject || !year || !type || !file) {
      if (message) message.textContent = "Please fill in all fields.";
      return;
    }

    if (file.type !== "application/pdf") {
      if (message) message.textContent = "Please upload a PDF file.";
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      if (message) message.textContent = "Maximum file size is 10 MB.";
      return;
    }

    papers.push({
      id: Date.now() + Math.random(),
      subject,
      year: Number(year),
      type,
      section: document.getElementById("uSection")?.value?.trim() || "",
      url: URL.createObjectURL(file),
      fileName: file.name,
      status: "pending"
    });

    if (message) {
      message.textContent =
        "Paper submitted for approval. This demo does not save files permanently.";
    }

    form.reset();
  });
}

// 7. SHOW / HIDE UPLOAD FORM

function setupUploadButtons() {
  const form = document.getElementById("uploadForm");
  const toggle = document.getElementById("toggleUpload");
  const cancel = document.getElementById("cancelUpload");

  if (toggle && form) {
    toggle.addEventListener("click", () => {
      form.hidden = !form.hidden;
    });
  }

  if (cancel && form) {
    cancel.addEventListener("click", () => {
      form.reset();
      form.hidden = true;

      const message = document.getElementById("uMsg");
      if (message) message.textContent = "";
    });
  }
}

// 8. START

function initPastPapers() {
  fillSubjects("fSubject");
  fillSubjects("uSubject");

  fillYears("fYear", true);
  fillYears("uYear", false);

  ["fSubject", "fYear", "fType"].forEach(id => {
    document.getElementById(id)?.addEventListener("change", show);
  });

  setupUpload();
  setupUploadButtons();
  show();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initPastPapers);
} else {
  initPastPapers();
}
