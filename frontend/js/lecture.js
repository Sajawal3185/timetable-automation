const $ = id => document.getElementById(id);

const SUBJECTS = [
  { code: "SEIT-111", name: "Application of Information and Communication Technology" },
  { code: "SEPF-121", name: "Programming Fundamentals" },
  { code: "SECA-117", name: "Calculus and Analytical Geometry" },
  { code: "SEDS-116", name: "Discrete Structures" },
  { code: "SEOO-122", name: "Object Oriented Programming" },
  { code: "SESE-123", name: "Software Engineering" },
  { code: "SEDA-225", name: "Data Structure and Algorithms" },
  { code: "SEOS-226", name: "Operating Systems" },
  { code: "SELA-201", name: "Linear Algebra" },
  { code: "SESD-242", name: "Software Design & Architecture" },
  { code: "SEFM-262", name: "Formal Methods in SE" },
  { code: "SEDB-227", name: "Database Systems" },
  { code: "SEBP-263", name: "Business Process Engineering" },
  { code: "SESR-241", name: "Software Requirements Engineering" },
  { code: "SESC-343", name: "Software Construction & Development" },
  { code: "SEAI-331", name: "Artificial Intelligence" },
  { code: "SEHC-364", name: "Human Computer Interaction" },
  { code: "SECN-228", name: "Computer Networks" },
  { code: "SEPS-303", name: "Probability & Statistics" },
  { code: "SEVP-365", name: "Visual Programming" },
  { code: "SEIS-432", name: "Information Security" },
  { code: "SESQ-344", name: "Software Quality Engineering" },
  { code: "SEWE-363", name: "Web Engineering" },
  { code: "SEMA-366", name: "Mobile App Development" },
  { code: "SESR-448", name: "Software Re-Engineering" },
  { code: "SESP-445", name: "Software Project Management" },
  { code: "SEOR-468", name: "Operation Research" },
  { code: "SEGD-468", name: "Game Development" },
  { code: "SEPI-468", name: "Software Process Improvement" },
  { code: "SEWE-467", name: "Web Engineering II" }
];

// Demo records can be added here (delete these two sample lines once you add real slides).
// Uploaded records are temporary until a backend is connected.
const lectures = [
  { id: 1, subjectCode: "SEDA-225", title: "Trees and Graphs",     year: 2024, fileName: "dsa-trees.pdf",  fileUrl: "../lectures/dsa-trees.pdf",  status: "approved" },
  { id: 2, subjectCode: "SEDA-225", title: "Stacks and Queues",    year: 2023, fileName: "dsa-stacks.pdf", fileUrl: "../lectures/dsa-stacks.pdf", status: "approved" },
  { id: 3, subjectCode: "SEOS-226", title: "Process Scheduling",   year: 2024, fileName: "os-scheduling.pdf", fileUrl: "../lectures/os-scheduling.pdf", status: "approved" }
];

function fillSubjects() {
  const filter = $("fSubject");
  const upload = $("uSubject");

  filter.replaceChildren(new Option("All Subjects", ""));
  upload.replaceChildren(new Option("Choose subject", ""));

  SUBJECTS.forEach(subject => {
    const label = `${subject.code} - ${subject.name}`;
    filter.add(new Option(label, subject.code));
    upload.add(new Option(label, subject.code));
  });
}

function fillYears() {
  const select = $("fYear");
  const currentYear = new Date().getFullYear();

  select.replaceChildren(new Option("All Years", ""));

  for (let year = currentYear; year >= 2000; year--) {
    select.add(new Option(String(year), String(year)));
  }
}

function showLectures() {
  const list = $("list");
  const empty = $("empty");
  const titleFilter = $("fTitle").value.trim().toLowerCase();
  const subjectFilter = $("fSubject").value;
  const yearFilter = $("fYear").value;

  list.replaceChildren();

  const results = lectures.filter(item =>
    item.status === "approved" &&
    (!titleFilter || item.title.toLowerCase().includes(titleFilter)) &&
    (!subjectFilter || item.subjectCode === subjectFilter) &&
    (!yearFilter || String(item.year) === yearFilter)
  );

  results.forEach(item => {
    const subject = SUBJECTS.find(s => s.code === item.subjectCode);

    const card = document.createElement("article");
    card.className = "paper-card";

    const title = document.createElement("h3");
    title.textContent = item.title;

    const course = document.createElement("p");
    course.textContent = subject
      ? `${subject.code} - ${subject.name}`
      : item.subjectCode;

    const year = document.createElement("p");
    year.textContent = `Year: ${item.year}`;

    const fileName = document.createElement("p");
    fileName.textContent = item.fileName;

    const actions = document.createElement("div");
    actions.className = "actions";

    const view = document.createElement("a");
    view.href = item.fileUrl;
    view.target = "_blank";
    view.rel = "noopener noreferrer";
    view.className = "btn";
    view.textContent = "View Slides";

    const download = document.createElement("a");
    download.href = item.fileUrl;
    download.download = item.fileName;
    download.className = "btn solid";
    download.textContent = "Download";

    actions.append(view, download);
    card.append(title, course, year, fileName, actions);
    list.append(card);
  });

  empty.hidden = results.length > 0;
}

function setupUpload() {
  const form = $("uploadForm");
  const message = $("uMsg");

  $("toggleUpload").addEventListener("click", () => {
    form.hidden = !form.hidden;
  });

  $("cancelUpload").addEventListener("click", () => {
    form.reset();
    form.hidden = true;
    message.textContent = "";
    message.className = "form-msg";
  });

  form.addEventListener("submit", event => {
    event.preventDefault();

    const subjectCode = $("uSubject").value;
    const title = $("uTitle").value.trim();
    const year = Number($("uYear").value);
    const file = $("uFile").files[0];

    message.textContent = "";
    message.className = "form-msg";

    if (!subjectCode || !title || !year || !file) {
      message.textContent = "Please fill in all fields.";
      message.classList.add("error");
      return;
    }

    if (!Number.isInteger(year) || year < 2000 || year > 2100) {
      message.textContent = "Please enter a valid year.";
      message.classList.add("error");
      return;
    }

    if (!/\.(pdf|ppt|pptx)$/i.test(file.name)) {
      message.textContent = "Only PDF, PPT and PPTX files are allowed.";
      message.classList.add("error");
      return;
    }

    if (file.size > 20 * 1024 * 1024) {
      message.textContent = "Maximum file size is 20 MB.";
      message.classList.add("error");
      return;
    }

    // TODO (backend team): send subjectCode, title, year and the file to the API (fetch POST, FormData).
    // The server saves it with status "pending" and must check the file again.
    lectures.push({
      id: Date.now(),
      subjectCode,
      title,
      year,
      fileName: file.name,
      fileUrl: URL.createObjectURL(file),
      status: "pending"
    });

    message.textContent = "Slides submitted for review. They will appear after approval.";
    message.classList.add("ok");

    form.reset();
  });
}

function initLectures() {
  fillSubjects();
  fillYears();
  showLectures();
  setupUpload();

  $("fTitle").addEventListener("input", showLectures);     // runs on every key typed
  $("fSubject").addEventListener("change", showLectures);
  $("fYear").addEventListener("change", showLectures);
}

document.addEventListener("DOMContentLoaded", initLectures);