# Postman collections

| File | What it covers |
|---|---|
| `demo.postman_collection.json` | The 5-minute demo (release plan, Part 8), top to bottom, with a test on every request. Use it as the smoke test after every deploy. |
| `portal.postman_collection.json` | Portal endpoints: rooms, exam papers, lecture materials, GPA, software, events. |

## Run the demo collection

1. Put the database back to its starting state. From `backend/`:

   ```bash
   SEED_PASSWORD='the-demo-password' python -m scripts.seed --reset
   ```

2. Import `demo.postman_collection.json` into Postman. On the collection's **Variables** tab set:
   - `baseUrl`: `http://127.0.0.1:8000` locally, or the Render URL for staging.
   - `password`: the same `SEED_PASSWORD`. Put it in **Current value** only, so it is never exported.

3. Click **Run collection** and run it top to bottom. All tests should pass.

Run the reset again before the next run: the demo swap and the makeup booking can only be made once.

Without the Postman app (needs Node.js):

```bash
npx newman run docs/postman/demo.postman_collection.json \
  --env-var baseUrl=http://127.0.0.1:8000 --env-var password='the-demo-password'
```

## What it checks

| Step | Checks |
|---|---|
| 0 | `/api/health` answers (wakes the Render server) |
| 1 | Student, teacher, HOD and admin log in; a wrong password gets 401 |
| 2 | The student sees their section's timetable |
| 3 | The teacher's swap into the known clashing slot is refused (409); the swap into the known free slot is created as `pending`; a student cannot approve it (403) |
| 4 | The HOD sees the request and approves it |
| 5 | The teacher has a `swap_approved` notification, marks it read, and the class is in its new slot |
| 6 | Find makeup slots for 90 minutes (lecture rooms only), book the first one, and booking the same slot again is refused (409) |
| 7 | The student has a `makeup_added` notification and sees the makeup class |
| 8 | Admin `/api/auth/me` |

The clashing and free slots come from `backend/scripts/seed.py`: Dr. Ahmed Raza's Monday 08:30 class clashes with Ms. Sara Khan's Monday 10:00 class, and his Wednesday 10:00 class swaps cleanly with her Wednesday 08:30 class.
