from dataclasses import dataclass


@dataclass
class ClassSession:
    day: str
    time: str
    subject: str
    room: str


def build_dummy_timetable() -> list[ClassSession]:
    return [
        ClassSession("Monday", "09:00", "Web Engineering", "Room 101"),
        ClassSession("Tuesday", "11:00", "Database Systems", "Lab 2"),
        ClassSession("Wednesday", "10:00", "Software Design", "Room 204"),
    ]


def main() -> None:
    print("Dummy timetable:")
    for session in build_dummy_timetable():
        print(
            f"{session.day:<10} {session.time} - "
            f"{session.subject} ({session.room})"
        )


if __name__ == "__main__":
    main()
