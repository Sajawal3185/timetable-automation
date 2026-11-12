const $ = id => document.getElementById(id);
const thisYear = new Date().getFullYear();

// ---------- Departments ----------
const DEPARTMENTS = [
  { code: "SE", name: "Software Engineering" },
  { code: "AI", name: "Artificial Intelligence" },
  { code: "CS", name: "Computer Science" },
  { code: "IT", name: "Information Technology" }
];

// ---------- Subjects ----------
const SUBJECTS = [];

function addSubjects(dept, list) {
  list.forEach(name => {
    SUBJECTS.push({ dept, name });
  });
}

// ---------- Software Engineering ----------
addSubjects("SE", [
  "Application of Information and Communication Technology",
  "Programming Fundamentals",
  "Calculus and Analytical Geometry",
  "Discrete Structures",
  "Object Oriented Programming",
  "Software Engineering",
  "Data Structure and Algorithms",
  "Linear Algebra",
  "Operating Systems",
  "Software Design & Architecture",
  "Formal Methods in SE",
  "Database Systems",
  "Business Process Engineering",
  "Software Requirements Engineering",
  "Probability & Statistics",
  "Software Construction & Development",
  "Artificial Intelligence",
  "Human Computer Interaction",
  "Computer Networks",
  "Visual Programming",
  "Information Security",
  "Software Quality Engineering",
  "Web Engineering",
  "Mobile App Development",
  "Software Re-Engineering",
  "Software Project Management",
  "Operation Research",
  "Game Development",
  "Software Process Improvement",
  "Web Engineering II",
  "Applied Physics",
  "Functional English",
  "Islamic Studies",
  "Ideology and Constitution of Pakistan",
  "Expository Writing",
  "Introduction to Psychology",
  "Technical & Business Writing",
  "Professional Practices",
  "Entrepreneurship",
  "Social Service",
  "Accounting Fundamentals"
]);

// ---------- Computer Science ----------
addSubjects("CS", [
  "Application of Information and Communication Technology",
  "Programming Fundamentals",
  "Math-I",
  "Calculus and Analytical Geometry",
  "Discrete Structures",
  "Object Oriented Programming",
  "Math-II",
  "Digital Logic Design",
  "Data Structures",
  "Database System",
  "Linear Algebra",
  "Artificial Intelligence",
  "Software Engineering",
  "Computer Organization and Assembly Language",
  "Web Technology",
  "Computer Architecture",
  "Analysis of Algorithms",
  "Multi Variable Calculus",
  "Statistics and Probability",
  "Operating System",
  "Web Engineering",
  "Theory of Automata",
  "Computer Networks",
  "Numerical Analysis",
  "Advanced Programming (VP)",
  "Advanced Database Management System",
  "HCI and Computer Graphics",
  "Compiler Construction",
  "Parallel and Distributed Computing",
  "Software Testing and Quality Assurance",
  "Mobile Application Development",
  "Cloud Computing",
  "Information Security",
  "Functional English",
  "Islamic Studies",
  "Applied Physics",
  "Pakistan Studies",
  "Expository Writing",
  "Civics and Community Engagement",
  "Ideology and Constitution of Pakistan",
  "Digital Marketing",
  "Fehm-e-Quran I",
  "Professional Practices",
  "Technology Entrepreneurship",
  "Fehm-e-Quran II",
  "Technical and Business Writing",
  "Foreign Language",
  "Financial Management"
]);

// ---------- Artificial Intelligence ----------
addSubjects("AI", [
  "Application of Information and Communication Technology",
  "Programming Fundamentals",
  "Functional English",
  "Applied Physics",
  "Islamic Studies",
  "Discrete Structures",
  "Math-I",
  "Object Oriented Programming",
  "Calculus and Analytical Geometry",
  "Digital Logic Design",
  "Expository Writing",
  "Civics and Community Engagement",
  "Ideology and Constitution of Pakistan",
  "Math-II",
  "Data Structures",
  "Programming for Artificial Intelligence",
  "Professional Practices",
  "Statistics and Probability",
  "Multivariable Calculus",
  "Computer Networks",
  "Digital Marketing",
  "Advanced Statistics",
  "Analysis of Algorithms",
  "Artificial Intelligence",
  "Database Systems",
  "Linear Algebra",
  "Artificial Neural Network",
  "Software Engineering",
  "Machine Learning",
  "Knowledge Representation & Reasoning",
  "Computer Organization & Assembly Language",
  "Accounting Fundamentals",
  "Operating System",
  "Computer Vision",
  "Natural Language Processing",
  "Data Mining",
  "Technical and Business Writing",
  "Parallel & Distributed Computing",
  "HCI & Computer Graphics",
  "Theory of Automata",
  "Fuzzy Systems",
  "Reinforcement Learning",
  "Technology Entrepreneurship",
  "Information Security"
]);

// ---------- Information Technology ----------
addSubjects("IT", [
  "Programming Fundamentals",
  "Application of Information and Communication Technology",
  "Islamic Studies",
  "Applied Physics",
  "Functional English",
  "Human Resource Management",
  "Expository Writing",
  "Object Oriented Programming",
  "Discrete Structures",
  "Software Engineering",
  "Calculus & Analytical Geometry",
  "Pakistan Studies",
  "Introduction to Psychology",
  "Data Structures",
  "Linear Algebra",
  "Enterprise System",
  "Professional Practices",
  "Computer Networks",
  "Ideology and Constitution of Pakistan",
  "Database Systems",
  "Operating Systems",
  "Software Requirement Engineering",
  "Multivariable Calculus",
  "Entrepreneurship",
  "Civics and Community Engagement",
  "System & Network Administration",
  "Database Administration & Management",
  "Digital Logic Design",
  "Analysis of Algorithm",
  "Information Security",
  "Digital Marketing",
  "Technical & Business Writing",
  "IT Infrastructure",
  "Artificial Intelligence",
  "Web Technologies",
  "Probability & Statistics",
  "Mobile Application Development",
  "Software Project Management",
  "Parallel & Distributed Computing",
  "Computer Organization & Assembly Language",
  "Virtual Systems and Services",
  "Cyber Security",
  "Fundamentals of DevOps",
  "Foreign Language"
]);

// ---------- Demo Papers (always approved) ----------
const papers = [
  {
    id: 1,
    dept: "SE",
    subject: "Data Structure and Algorithms",
    year: 2024,
    type: "Midterm",
    status: "approved",
    files: [
      {
        name: "dsa-2024-midterm.pdf",
        url: "../papers/dsa-2024-midterm.pdf"
      }
    ]
  },
  {
    id: 2,
    dept: "SE",
    subject: "Operating Systems",
    year: 2023,
    type: "Final",
    status: "approved",
    files: [
      {
        name: "os-2023-final-page1.jpg",
        url: "../papers/os-2023-final-1.jpg"
      },
      {
        name: "os-2023-final-page2.jpg",
        url: "../papers/os-2023-final-2.jpg"
      }
    ]
  },
  {
    id: 3,
    dept: "SE",
    subject: "Database Systems",
    year: 2023,
    type: "Final",
    status: "approved",
    files: [
      {
        name: "database-2023-final.pdf",
        url: "../papers/database-2023-final.pdf"
      }
    ]
  }
];

// ---------- Approved student uploads ----------
// Students' uploads wait in UploadStore until an admin approves them on
// review-uploads.html. Only approved ones are loaded here.
let approvedUploads = [];
let uploadUrls = [];

async function loadApprovedUploads() {
  uploadUrls.forEach(url => URL.revokeObjectURL(url));
  uploadUrls = [];

  const loaded = [];

  for (const item of UploadStore.list({ kind: "paper", status: "approved" })) {
    try {
      const files = await UploadStore.getFiles(item);

      loaded.push({
        id: item.id,
        dept: item.dept,
        subject: item.subject,
        year: item.year,
        type: item.type,
        status: "approved",
        files: files.map(file => {
          const url = URL.createObjectURL(file.blob);
          uploadUrls.push(url);
          return { name: file.name, url };
        })
      });
    } catch (error) {
      // File missing from this browser's storage: skip this paper.
    }
  }

  approvedUploads = loaded;
  show();
}

// ---------- Dropdown Helpers ----------
function fill(select, items, placeholder) {
  select.replaceChildren();

  const firstOption = new Option(placeholder, "");
  firstOption.disabled = true;
  firstOption.selected = true;
  select.add(firstOption);

  items.forEach(item => {
    select.add(new Option(item.label, String(item.value)));
  });

  select.value = "";
}

function lock(select, locked) {
  select.disabled = locked;
  select.classList.toggle("locked", locked);
}

// ---------- Department Options ----------
function getDepartmentOptions() {
  return DEPARTMENTS.map(dept => ({
    value: dept.code,
    label: `${dept.code} - ${dept.name}`
  }));
}

// ---------- Subject Options ----------
function getSubjectOptions(dept) {
  return SUBJECTS
    .filter(subject => subject.dept === dept)
    .map(subject => ({
      value: subject.name,
      label: subject.name
    }));
}

// ---------- Years ----------
function getYears() {
  const years = [];

  for (let year = thisYear; year >= 2020; year--) {
    years.push({
      value: year,
      label: String(year)
    });
  }

  return years;
}

// ---------- Paper Types ----------
function getPaperTypes() {
  return [
    { value: "Midterm", label: "Midterm" },
    { value: "Final", label: "Final" }
  ];
}

// ---------- Initialise Dropdowns ----------
function initDropdowns() {
  fill($("fDept"), getDepartmentOptions(), "Choose Department");
  fill($("fSubject"), [], "Choose Subject");
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  fill($("uDept"), getDepartmentOptions(), "Choose Department");
  fill($("uSubject"), [], "Choose Subject");
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  // Filter sequence
  lock($("fDept"), false);
  lock($("fSubject"), true);
  lock($("fYear"), true);
  lock($("fType"), true);

  // Upload sequence
  lock($("uDept"), false);
  lock($("uSubject"), true);
  lock($("uYear"), true);
  lock($("uType"), true);
}

// ---------- Display Papers ----------
function show() {
  const dept = $("fDept").value;
  const subject = $("fSubject").value;
  const year = $("fYear").value;
  const type = $("fType").value;

  const list = $("list");
  list.replaceChildren();

  if (!dept || !subject || !year || !type) {
    $("hint").hidden = false;
    $("empty").hidden = true;
    return;
  }

  $("hint").hidden = true;

  const results = papers
    .concat(approvedUploads)
    .filter(paper => paper.status === "approved")
    .filter(paper => paper.dept === dept)
    .filter(paper => paper.subject === subject)
    .filter(paper => String(paper.year) === year)
    .filter(paper => paper.type === type);

  results.forEach(paper => {
    const card = document.createElement("article");
    card.className = "paper-card";

    const heading = document.createElement("h3");
    heading.textContent = paper.subject;

    const details = document.createElement("p");
    details.textContent =
      `${paper.dept} · ${paper.year} · ${paper.type}`;

    const fileList = document.createElement("ul");
    fileList.className = "files";

    paper.files.forEach(file => {
      const item = document.createElement("li");

      const fileName = document.createElement("span");
      fileName.textContent = file.name;

      const view = document.createElement("a");
      view.className = "btn";
      view.href = file.url;
      view.target = "_blank";
      view.rel = "noopener";
      view.textContent = "View";

      const download = document.createElement("a");
      download.className = "btn solid";
      download.href = file.url;
      download.download = file.name;
      download.textContent = "Download";

      item.append(fileName, view, download);
      fileList.append(item);
    });

    card.append(heading, details, fileList);
    list.append(card);
  });

  $("empty").hidden = results.length > 0;
}

// ---------- Filter Events ----------
$("fDept").addEventListener("change", () => {
  const dept = $("fDept").value;

  fill($("fSubject"), getSubjectOptions(dept), "Choose Subject");
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fSubject"), !dept);
  lock($("fYear"), true);
  lock($("fType"), true);

  show();
});

$("fSubject").addEventListener("change", () => {
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fYear"), !$("fSubject").value);
  lock($("fType"), true);

  show();
});

$("fYear").addEventListener("change", () => {
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fType"), !$("fYear").value);

  show();
});

$("fType").addEventListener("change", show);

// ---------- Upload Events ----------
$("uDept").addEventListener("change", () => {
  const dept = $("uDept").value;

  fill($("uSubject"), getSubjectOptions(dept), "Choose Subject");
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uSubject"), !dept);
  lock($("uYear"), true);
  lock($("uType"), true);
});

$("uSubject").addEventListener("change", () => {
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uYear"), !$("uSubject").value);
  lock($("uType"), true);
});

$("uYear").addEventListener("change", () => {
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uType"), !$("uYear").value);
});

// ---------- Reset Upload Form ----------
function resetUploadForm() {
  $("uploadForm").reset();

  fill($("uDept"), getDepartmentOptions(), "Choose Department");
  fill($("uSubject"), [], "Choose Subject");
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uDept"), false);
  lock($("uSubject"), true);
  lock($("uYear"), true);
  lock($("uType"), true);
}

// ---------- Open and Close Upload Form ----------
$("toggleUpload").addEventListener("click", () => {
  $("uploadForm").hidden = !$("uploadForm").hidden;
  $("uMsg").textContent = "";
  $("uMsg").className = "form-msg";
});

$("cancelUpload").addEventListener("click", () => {
  resetUploadForm();
  $("uploadForm").hidden = true;
  $("uMsg").textContent = "";
});

// ---------- Submit Paper ----------
$("uploadForm").addEventListener("submit", async event => {
  event.preventDefault();

  const message = $("uMsg");
  const dept = $("uDept").value;
  const subject = $("uSubject").value;
  const year = Number($("uYear").value);
  const type = $("uType").value;
  const files = Array.from($("uFile").files);

  message.className = "form-msg error";

  if (!dept || !subject || !year || !type) {
    message.textContent = "Please complete all fields.";
    return;
  }

  if (year < 2020 || year > thisYear) {
    message.textContent =
      `Please select a year from 2020 to ${thisYear}.`;
    return;
  }

  if (files.length === 0) {
    message.textContent = "Please choose a file.";
    return;
  }

  if (files.length > 10) {
    message.textContent = "You can upload a maximum of 10 files.";
    return;
  }

  for (const file of files) {
    if (!/\.(pdf|jpe?g|png|webp)$/i.test(file.name)) {
      message.textContent =
        "Only PDF, JPG, PNG and WEBP files are allowed.";
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      message.textContent =
        `${file.name} exceeds the 10 MB limit.`;
      return;
    }
  }

  const submitButton = $("uploadForm").querySelector('button[type="submit"]');
  submitButton.disabled = true;
  message.className = "form-msg";
  message.textContent = "Uploading...";

  try {
    await UploadStore.add({
      kind: "paper",
      dept,
      subject,
      year,
      type,
      title: `${subject} ${year}`,
      uploadedBy: NUML.getUser() ? NUML.getUser().name : "Student"
    }, files);

    resetUploadForm();

    message.className = "form-msg ok";
    message.textContent =
      "Thank you! Your paper was sent for review. It will appear here once an admin approves it.";
  } catch (error) {
    message.className = "form-msg error";
    message.textContent = error.message || "Upload failed. Please try again.";
  } finally {
    submitButton.disabled = false;
  }
});

// ---------- Start ----------
initDropdowns();
show();
loadApprovedUploads();