 1. Authentication [SuperAdmin for Registration]

Case A: Login (Happy Path) [Anyone]
*   **Method**: `POST`
*   **URL**: `.../api/auth/login`
*   **Body**: `{"email": "superadmin@ems.com", "password": "Password@123"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Login successful"`
    *   **Action**: Copy the `access_token` and `csrf_token` from the response. You'll need them for all other tests!

Case B: Register a Hospital Admin [SuperAdmin Only]
*   **Method**: `POST`
*   **URL**: `.../api/auth/register`
*   **Body**: 
    ```json
    {
      "tenant_id": 1, 
      "name": "Hospital Admin",
      "email": "admin@mepz.com",
      "password": "Password@123",
      "role_id": 1,
      "gender": "male",
      "phone_number": "9988776655",
      "address": "123 Admin St"
    }
    ```
*   **Note**: All fields are required except `address`. `role_id: 1` is for Admin. 
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Admin registered successfully"`

### Case B: Wrong Password (Error Test)
*   **Body**: `{"email": "superadmin@ems.com", "password": "wrong_password"}`
*   **❌ What you should see**:
    *   **Status**: `401 Unauthorized`
    *   **Message**: `"Invalid credentials"`

---

## 🏢 2. Tenant Management [SuperAdmin Only]

### Case A: Create a New Hospital [SuperAdmin Only]
*   **Method**: `POST`
*   **URL**: `.../api/tenants`
*   **Body**: `{"name": "City Clinic", "domain": "city.com", "address": "123 Main St", "phone": "9988776655"}`
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Tenant created successfully"`
    *   **Data**: You will see an `id` (e.g., `5`). Remember this ID!

### Case B: View All Hospitals
*   **Method**: `GET`
*   **URL**: `.../api/tenants`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Data**: A list of all hospital names, addresses, and emails.

### Case C: Update Hospital Info
*   **Method**: `PUT`
*   **URL**: `.../api/tenants/5` (Use the ID you got above)
*   **Body**: `{"address": "456 New St", "phone": "1122334455"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Tenant updated successfully"`

### Case C: Delete a Hospital
*   **Method**: `DELETE`
*   **URL**: `.../api/tenants/5`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Tenant deleted successfully"` (The hospital stays in the DB but is marked as 'deleted').

---

## 👩‍⚕️ 3. Staff Management [Admin & SuperAdmin Only]

### Case A: Register a Doctor/Staff [Admin & SuperAdmin]
*   **Method**: `POST`
*   **URL**: `.../api/staff/register`
*   **Body**: 
    ```json
    {
      "name": "Dr. House", 
      "email": "house@general.hospital", 
      "password": "Password@123", 
      "role_id": 2, 
      "gender": "male", 
      "phone_number": "9988112233",
      "address": "Princeton-Plainsboro"
    }
    ```
*   **Note**: All fields are mandatory. Email and Phone must be unique.
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Staff registered successfully"`

### Case B: View Staff List
*   **Method**: `GET`
*   **URL**: `.../api/staff`
*   **✅ What you should see**: A list of all doctors, nurses, etc. in your clinic. **Now includes their Email and Role!**

### Case C: Update Staff Info
*   **Method**: `PUT`
*   **URL**: `.../api/staff/2` (Change '2' to a real staff ID)
*   **Body**: `{"phone_number": "123-456-7890", "status": "inactive"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Staff updated successfully"`

### Case D: Remove Staff Member
*   **Method**: `DELETE`
*   **URL**: `.../api/staff/2`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Staff deleted successfully"`

---

## 🧪 4. Patient Management [Provider & Nurse Only]

### Case A: Add a Patient [Provider & Nurse]
*   **Method**: `POST`
*   **URL**: `.../api/patients`
*   **Body**: `{"first_name": "Alice", "last_name": "Smith", "dob": "1990-01-01", "gender": "female", "medical_history": "Allergy to nuts"}`
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Patient created successfully"`

### Case B: View Patients
*   **Method**: `GET`
*   **URL**: `.../api/patients`
*   **✅ What you should see**: A list of all patients. The `medical_history` will be clear text (it was encrypted in the background!).

### Case C: Update Patient Info
*   **Method**: `PUT`
*   **URL**: `.../api/patients/1` (Change '1' to a real patient ID)
*   **Body**: `{"medical_history": "Recovered from nut allergy"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Patient updated successfully"`

### Case D: Remove Patient
*   **Method**: `DELETE`
*   **URL**: `.../api/patients/1`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Patient deleted successfully"`

---

## 📅 5. Appointments & Scheduling [Staff Only]

### Case A: Book Appointment [Provider, Nurse, Admin, Receptionist]
*   **Method**: `POST`
*   **URL**: `.../api/appointments`
*   **Body**: `{"patient_id": 1, "provider_id": 2, "appointment_date": "2026-05-01", "start_time": "11:00", "end_time": "11:30"}`
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Appointment scheduled successfully"`
    *   **Note**: Cannot book for past dates. Nurses/Admins must specify `provider_id`.

### Case B: Update Appointment [Staff Only]
*   **Method**: `PUT`
*   **URL**: `.../api/appointments/update/1` (Change '1' to your appointment ID)
*   **Body**: `{"appointment_date": "2026-05-02", "start_time": "14:00", "end_time": "14:30"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Appointment updated successfully"`

### Case C: View Upcoming Appointments
*   **Method**: `GET`
*   **URL**: `.../api/appointments/upcoming`
*   **✅ What you should see**: A list of all your clinic's future scheduled appointments.

### Case D: Mark Appointment as Completed [Provider & Admin Only]
*   **Method**: `PUT`
*   **URL**: `.../api/appointments/complete/1`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Completed"`

### Case E: Cancel Appointment [Staff Only]
*   **Method**: `PUT`
*   **URL**: `.../api/appointments/cancel/1`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Cancelled"`

### Case F: View Single Appointment Details
*   **Method**: `GET`
*   **URL**: `.../api/appointments/show/1`
*   **✅ What you should see**: Full details of the appointment, including patient and provider info.

---

## 💰 6. Billing & Payments [Admin & Receptionist Only]

### Case A: Create Bill [Admin, Provider, Receptionist]
*   **Method**: `POST`
*   **URL**: `.../api/invoices`
*   **Body**: `{"appointment_id": 1, "amount": 250.00}`
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Invoice created successfully"`

### Case B: Update Invoice [Admin & Receptionist Only]
*   **Method**: `PUT`
*   **URL**: `.../api/invoices/1` (Change '1' to real invoice ID)
*   **Body**: `{"amount": 300.00, "status": "paid"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Invoice updated successfully"`

---

## 🔐 7. Security [Any Logged-in User]

### Case A: Change Your Password [Any Logged-in User]
*   **Method**: `POST`
*   **URL**: `.../api/auth/change-password`
*   **Body**: `{"current_password": "Password@123", "new_password": "NewSecret@999"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Password changed successfully"`

### Case B: Incorrect Current Password (Error Test)
*   **Body**: `{"current_password": "WRONG_OLD_PASS", "new_password": "NewSecret@999"}`
*   **❌ What you should see**:
    *   **Status**: `401 Unauthorized`
    *   **Message**: `"Incorrect current password"`

---

## 📊 8. Dashboard [Admin, Provider, Nurse, Pharmacist, Receptionist]

### Case A: View Clinic Stats [Clinic Staff Only]
*   **Method**: `GET`
*   **URL**: `.../api/dashboard/stats`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Data**: A summary of `patients_total`, `appointments_today`, `appointments_upcoming`, etc. (Admins also see `staff_total`).

---

## � 11. Prescriptions [Provider & Pharmacist Only]

### Case A: Create a Prescription [Provider Only]
*   **Method**: `POST`
*   **URL**: `.../api/prescriptions`
*   **Body**: `{"appointment_id": 1, "notes": "Paracetamol 500mg - twice a day for 3 days."}`
*   **Note**: You can only create a prescription for an appointment that is already marked as **completed**.
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Prescription created successfully"`

### Case B: Verify/Update Prescription Status [Pharmacist Only]
*   **Method**: `PUT`
*   **URL**: `.../api/prescriptions/1/status` (Change '1' to real prescription ID)
*   **Body**: `{"status": "verified"}`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Message**: `"Prescription status updated to verified"`

### Case C: View All Prescriptions [Provider, Pharmacist, Admin]
*   **Method**: `GET`
*   **URL**: `.../api/prescriptions`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Data**: A list of all prescriptions in your clinic.

---

## �💬 9. Communication (Appointment Notes) [Staff & Patients]

### Case A: Add a Note to an Appointment [Clinic Staff]
*   **Method**: `POST`
*   **URL**: `.../api/communications`
*   **Body**: `{"appointment_id": 1, "note": "Patient showed improvement.", "is_private": 0}`
*   **✅ What you should see**:
    *   **Status**: `201 Created`
    *   **Message**: `"Note added successfully."`

### Case B: View Notes for an Appointment [Staff & Patients]
*   **Method**: `GET`
*   **URL**: `.../api/communications?appointment_id=1`
*   **✅ What you should see**:
    *   **Status**: `200 OK`
    *   **Data**: A list of notes. **Note**: Patients can only see public notes (`is_private: 0`).

---

## 📅 12. Calendar System [Staff Only]

### Case A: Get Month View Data (For Calendar Grid)
*   **Method**: `GET`
*   **URL**: `.../api/calendar/range?start=2026-03-01&end=2026-03-31`
*   **✅ What you should see**:
*       **Status**: `200 OK`
*       **Data**: A list of dates within the range, each containing a summary of appointments (count and basic details).
    ```json
    {
        "success": true,
        "message": "Calendar month data",
        "data": [
            {
                "date": "2026-03-20",
                "total_appointments": 1,
                "appointments": [
                    {
                        "time": "10:00 AM",
                        "patient": "John Doe",
                        "status": "scheduled",
                        "doctor": "Dr. House"
                    }
                ]
            }
        ]
    }
    ```

### Case B: Get Single Date Details (For Tooltip/Popup)
*   **Method**: `GET`
*   **URL**: `.../api/calendar/date?date=2026-03-20`
*   **✅ What you should see**:
*       **Status**: `200 OK`
*       **Data**: Detailed list of appointments for that specific day, including decrypted medical history.
    ```json
    {
        "success": true,
        "message": "Selected date appointments",
        "data": [
            {
                "date": "2026-03-20",
                "time": "10:00 AM - 11:00 AM",
                "status": "scheduled",
                "patient": {
                    "full_name": "John Doe",
                    "medical_history": "Diagnosed with mild flu. Prescribed Paracetamol."
                },
                "doctor": {
                    "name": "Dr. House",
                    "phone": null,
                    "email": "house@hospital1.com"
                }
            }
        ]
    }
    ```


