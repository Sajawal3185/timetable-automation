
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
  list.forEach(([code, name]) => {
    SUBJECTS.push({ dept, code, name });
  });
}

addSubjects("SE", [
  ["SEIT-111", "Application of Information and Communication Technology"],
  ["SEPF-121", "Programming Fundamentals"],
  ["SECA-117", "Calculus and Analytical Geometry"],
  ["SEDS-116", "Discrete Structures"],
  ["SEOO-122", "Object Oriented Programming"],
  ["SESE-123", "Software Engineering"],
  ["SEDA-225", "Data Structure and Algorithms"],
  ["SELA-201", "Linear Algebra"],
  ["SEOS-226", "Operating Systems"],
  ["SESD-242", "Software Design & Architecture"],
  ["SEFM-262", "Formal Methods in SE"],
  ["SEDB-227", "Database Systems"],
  ["SEBP-263", "Business Process Engineering"],
  ["SESR-241", "Software Requirements Engineering"],
  ["SEPS-303", "Probability & Statistics"],
  ["SESC-343", "Software Construction & Development"],
  ["SEAI-331", "Artificial Intelligence"],
  ["SEHC-364", "Human Computer Interaction"],
  ["SECN-228", "Computer Networks"],
  ["SEVP-365", "Visual Programming"],
  ["SEIS-432", "Information Security"],
  ["SESQ-344", "Software Quality Engineering"],
  ["SEWE-363", "Web Engineering"],
  ["SEMA-366", "Mobile App Development"],
  ["SESR-448", "Software Re-Engineering"],
  ["SESP-445", "Software Project Management"],
  ["SEOR-468", "Operation Research"],
  ["SEGD-468", "Game Development"],
  ["SEPI-468", "Software Process Improvement"],
  ["SEWE-467", "Web Engineering II"],
  ["SEAP-113", "Applied Physics"],
  ["SEFE-114", "Functional English"],
  ["IS-112", "Islamic Studies"],
  ["PS-118", "Ideology and Constitution of Pakistan"],
  ["SEEW-115", "Expository Writing"],
  ["SEIP-219", "Introduction to Psychology"],
  ["SETB-304", "Technical & Business Writing"],
  ["SEPP-422", "Professional Practices"],
  ["SEEP-421", "Entrepreneurship"],
  ["SESS-367", "Social Service"],
  ["SEAF-161", "Accounting Fundamentals"]
]);

addSubjects("CS", [
  ["CSIT-107", "Application of Information and Communication Technology"],
  ["CSPF-112", "Programming Fundamentals"],
  ["MTPM-161", "Math-I"],
  ["MTCA-113", "Calculus and Analytical Geometry"],
  ["CSDT-116", "Discrete Structures"],
  ["CSOO-117", "Object Oriented Programming"],
  ["MTPM-162", "Math-II"],
  ["CSDL-207", "Digital Logic Design"],
  ["CSDS-208", "Data Structures"],
  ["CSDB-210", "Database System"],
  ["MTLA-211", "Linear Algebra"],
  ["CSAI-213", "Artificial Intelligence"],
  ["CSSE-214", "Software Engineering"],
  ["CSCO-215", "Computer Organization and Assembly Language"],
  ["CSWT-216", "Web Technology"],
  ["CSCA-340", "Computer Architecture"],
  ["CSAA-341", "Analysis of Algorithms"],
  ["MTMV-342", "Multi Variable Calculus"],
  ["MTSP-343", "Statistics and Probability"],
  ["CSOS-344", "Operating System"],
  ["CSWE-345", "Web Engineering"],
  ["CSTA-347", "Theory of Automata"],
  ["CSCN-348", "Computer Networks"],
  ["CSNA-349", "Numerical Analysis"],
  ["CSAP-350", "Advanced Programming (VP)"],
  ["CSAD-351", "Advanced Database Management System"],
  ["CSCG-420", "HCI and Computer Graphics"],
  ["CSCC-421", "Compiler Construction"],
  ["CSDC-422", "Parallel and Distributed Computing"],
  ["SEST-423", "Software Testing and Quality Assurance"],
  ["CSMA-424", "Mobile Application Development"],
  ["CSCD-427", "Cloud Computing"],
  ["CSIS-428", "Information Security"],
  ["ESFE-108", "Functional English"],
  ["SSIS-109", "Islamic Studies"],
  ["EEAP-110", "Applied Physics"],
  ["SSPS-111", "Pakistan Studies"],
  ["ESEW-114", "Expository Writing"],
  ["SSCE-115", "Civics and Community Engagement"],
  ["SSIP-118", "Ideology and Constitution of Pakistan"],
  ["CSDM-205", "Digital Marketing"],
  ["SSFQ-206", "Fehm-e-Quran I"],
  ["CSPP-212", "Professional Practices"],
  ["MSTE-214", "Technology Entrepreneurship"],
  ["SSFQ-241", "Fehm-e-Quran II"],
  ["ESTW-352", "Technical and Business Writing"],
  ["FLxx-426", "Foreign Language"],
  ["MSFM-425", "Financial Management"]
]);

addSubjects("AI", [
  ["CSIT-107", "Application of Information and Communication Technology"],
  ["CSPF-121", "Programming Fundamentals"],
  ["ESFE-108", "Functional English"],
  ["CSDT-110", "Applied Physics"],
  ["EEAP-109", "Islamic Studies"],
  ["SSIS-111", "Discrete Structures"],
  ["MTPM-161", "Maths-I"],
  ["CSOO-122", "Object Oriented Programming"],
  ["MTCA-112", "Calculus and Analytical Geometry"],
  ["CSDL-123", "Digital Logic Design"],
  ["ESEW-113", "Expository Writing"],
  ["SSCE-114", "Civics and Community Engagement"],
  ["SSIP-115", "Ideology and Constitution of Pakistan"],
  ["MTPM-162", "Pre-Maths-II"],
  ["CSDS-224", "Data Structures"],
  ["CSPA-241", "Programming for Artificial Intelligence"],
  ["CSPP-216", "Professional Practices"],
  ["MTSP-201", "Statistics & Probability"],
  ["MTMC-202", "Multivariable Calculus"],
  ["CSCN-225", "Computer Networks"],
  ["CSDM-217", "Digital Marketing"],
  ["MTAS-263", "Advanced Statistics"],
  ["CSAA-226", "Analysis of Algorithms"],
  ["CSAI-227", "Artificial Intelligence"],
  ["CSDB-228", "Database Systems"],
  ["MTLA-203", "Linear Algebra"],
  ["CSNN-342", "Artificial Neural Network"],
  ["CSSE-329", "Software Engineering"],
  ["CSML-343", "Machine Learning"],
  ["CSKR-344", "Knowledge Representation & Reasoning"],
  ["CSCO-330", "Computer Organization & Assembly Language"],
  ["MSAF-376", "Accounting Fundamentals"],
  ["CSOS-331", "Operating System"],
  ["CSCV-345", "Computer Vision"],
  ["CSNL-364", "Natural Language Processing"],
  ["CSDM-365", "Data Mining"],
  ["ESTW-304", "Technical and Business Writing"],
  ["CSDC-446", "Parallel & Distributed Computing"],
  ["CSCG-466", "HCI & Computer Graphics"],
  ["CSTA-467", "Theory of Automata"],
  ["CSFS-468", "Fuzzy Systems"],
  ["CSRL-469", "Reinforcement Learning"],
  ["MSTE-418", "Technology Entrepreneurship"],
  ["CSIS-432", "Information Security"]
]);

addSubjects("IT", [
  ["ITPF-121", "Programming Fundamentals"],
  ["ITIT-111", "Application to Information & Communication Technology"],
  ["IS-112", "Islamic Studies"],
  ["NSAP-103", "Applied Physics"],
  ["ESFE-104", "Functional English"],
  ["ITHR-176", "Human Resource Management"],
  ["ESEW-105", "Expository Writing"],
  ["ITOO-127", "Object Oriented Programming"],
  ["QRDS-106", "Discrete Structures"],
  ["ITSE-128", "Software Engineering"],
  ["QRCA-107", "Calculus & Analytical Geometry"],
  ["SSPS-108", "Pakistan Studies"],
  ["SSIP-209", "Introduction to Psychology"],
  ["ITDA-229", "Data Structures"],
  ["ITLA-218", "Linear Algebra"],
  ["ITES-277", "Enterprise System"],
  ["AHPP-210", "Professional Practices"],
  ["ITCN-230", "Computer Networks"],
  ["SSCP-211", "Ideology and Constitution of Pakistan"],
  ["ITDB-231", "Database Systems"],
  ["ITOS-232", "Operating Systems"],
  ["ITSR-278", "Software Requirement Engineering"],
  ["ITMV-219", "Multivariable Calculus"],
  ["MSEP-212", "Entrepreneurship"],
  ["SSCE-213", "Civics and Community Engagement"],
  ["ITSN-333", "System & Network Administration"],
  ["ITDM-334", "Database Administration & Management"],
  ["ITDL-335", "Digital Logic Design"],
  ["ITAO-336", "Analysis of Algorithm"],
  ["ITIS-337", "Information Security"],
  ["ITDK-379", "Digital Marketing"],
  ["ITTB-320", "Technical & Business Writing"],
  ["ITII-338", "IT Infrastructure"],
  ["ITAI-339", "Artificial Intelligence"],
  ["ITWT-340", "Web Technologies"],
  ["ITPS-321", "Probability & Statistics"],
  ["ITMA-380", "Mobile Application Development"],
  ["ITPM-481", "Software Project Management"],
  ["ITPC-441", "Parallel & Distributed Computing"],
  ["ITCO-442", "Computer Organization & Assembly Language"],
  ["ITVS-482", "Virtual Systems and Services"],
  ["ITCS-443", "Cyber Security"],
  ["ITDO-484", "Fundamentals of DevOps"],
  ["ITFL-486", "Foreign Language"]
]);

// ---------- Demo Papers ----------
const papers = [
  {
    id: 1,
    dept: "SE",
    subjectCode: "SEDA-225",
    teacher: "Sir Ali",
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
    subjectCode: "SEOS-226",
    teacher: "Ms Sara",
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
    subjectCode: "SEDB-227",
    teacher: "Sir Ahmed",
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

function getDepartmentOptions() {
  return DEPARTMENTS.map(dept => ({
    value: dept.code,
    label: `${dept.code} - ${dept.name}`
  }));
}

function getSubjectOptions(dept) {
  return SUBJECTS
    .filter(subject => subject.dept === dept)
    .map(subject => ({
      value: subject.code,
      label: `${subject.code} - ${subject.name}`
    }));
}

function getSubjectName(dept, code) {
  const subject = SUBJECTS.find(
    item => item.dept === dept && item.code === code
  );

  return subject ? subject.name : code;
}

// ---------- Teacher Options ----------
function getTeacherOptions(dept, subjectCode) {
  const teachers = [...new Set(
    papers
      .filter(paper =>
        paper.status === "approved" &&
        paper.dept === dept &&
        paper.subjectCode === subjectCode &&
        paper.teacher &&
        paper.teacher.trim() !== ""
      )
      .map(paper => paper.teacher.trim())
  )];

  return teachers.sort().map(teacher => ({
    value: teacher,
    label: teacher
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
  fill($("fTeacher"), [], "Choose Teacher");
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  fill($("uDept"), getDepartmentOptions(), "Choose Department");
  fill($("uSubject"), [], "Choose Subject");
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  // Filter sequence
  lock($("fDept"), false);
  lock($("fSubject"), true);
  lock($("fTeacher"), true);
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
  const subjectCode = $("fSubject").value;
  const teacher = $("fTeacher").value;
  const year = $("fYear").value;
  const type = $("fType").value;

  const list = $("list");
  list.replaceChildren();

  if (!dept || !subjectCode || !teacher || !year || !type) {
    $("hint").hidden = false;
    $("empty").hidden = true;
    return;
  }

  $("hint").hidden = true;

  const results = papers
    .filter(paper => paper.status === "approved")
    .filter(paper => paper.dept === dept)
    .filter(paper => paper.subjectCode === subjectCode)
    .filter(paper => paper.teacher === teacher)
    .filter(paper => String(paper.year) === year)
    .filter(paper => paper.type === type);

  results.forEach(paper => {
    const card = document.createElement("article");
    card.className = "paper-card";

    const heading = document.createElement("h3");
    heading.textContent = getSubjectName(
      paper.dept,
      paper.subjectCode
    );

    const details = document.createElement("p");
    details.textContent =
      `${paper.dept} · ${paper.teacher} · ${paper.year} · ${paper.type}`;

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
  fill($("fTeacher"), [], "Choose Teacher");
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fSubject"), !dept);
  lock($("fTeacher"), true);
  lock($("fYear"), true);
  lock($("fType"), true);

  show();
});

$("fSubject").addEventListener("change", () => {
  const dept = $("fDept").value;
  const subject = $("fSubject").value;

  fill(
    $("fTeacher"),
    getTeacherOptions(dept, subject),
    "Choose Teacher"
  );

  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fTeacher"), !subject);
  lock($("fYear"), true);
  lock($("fType"), true);

  show();
});

$("fTeacher").addEventListener("change", () => {
  fill($("fYear"), getYears(), "Choose Year");
  fill($("fType"), getPaperTypes(), "Choose Paper Type");

  lock($("fYear"), !$("fTeacher").value);
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
  $("uTeacher").value = "";

  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uSubject"), !dept);
  lock($("uYear"), true);
  lock($("uType"), true);
});

$("uSubject").addEventListener("change", () => {
  $("uTeacher").value = "";

  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uYear"), true);
  lock($("uType"), true);
});

$("uTeacher").addEventListener("input", () => {
  fill($("uYear"), getYears(), "Choose Year");
  fill($("uType"), getPaperTypes(), "Choose Paper Type");

  lock($("uYear"), !$("uTeacher").value.trim());
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
$("uploadForm").addEventListener("submit", event => {
  event.preventDefault();

  const message = $("uMsg");
  const dept = $("uDept").value;
  const subjectCode = $("uSubject").value;
  const teacher = $("uTeacher").value.trim();
  const year = Number($("uYear").value);
  const type = $("uType").value;
  const files = Array.from($("uFile").files);

  message.className = "form-msg error";

  if (!dept || !subjectCode || !teacher || !year || !type) {
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

  papers.push({
    id: Date.now(),
    dept,
    subjectCode,
    teacher,
    year,
    type,
    status: "pending",
    files: files.map(file => ({
      name: file.name,
      url: URL.createObjectURL(file)
    }))
  });

  resetUploadForm();

  message.className = "form-msg ok";
  message.textContent =
    "Demo only: nothing was uploaded. Uploads will work once the backend is connected.";
});

// ---------- Start ----------
initDropdowns();
show();
