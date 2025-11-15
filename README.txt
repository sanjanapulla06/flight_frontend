✈️ AIRPORT MANAGEMENT SYSTEM — DBMS MINI PROJECT

PES University — UE23CS351A (DBMS Course Project)

👥 Team Members

Sanjana Pulla (PES2UG23CS529) — User Module

Sharon (PES2UG23CS544) — Admin Module

📌 Project Overview

The Airport Management System (AMS) is a database-based mini project designed to manage flights, passengers, bookings, ticketing, airlines, and payments.
It covers the complete DBMS workflow — from ER modeling to stored procedures — and shows how real-world airport data can be organized and accessed efficiently.

The workload was divided as:

User Module (Sanjana) → viewing flights, searching schedules, ticket info

Admin Module (Sharon) → backend management like adding flights, editing data, database operations

🗂️ Features
✨ User Features

View available flights

Check status (On-Time, Delayed, Cancelled)

Search by city, date, airline

View prices, classes, seat info

View ticket/booking details

🛠️ Admin Features

Add / update / delete flights

Manage passengers and bookings

Insert ticket details

Run stored procedures

Automatic payment creation using triggers

🔧 Tech Stack
Component	Technology
Database	MySQL
Logic	Stored Procedures, Functions, Triggers
Frontend (Optional)	Basic HTML / CSS
Tools	MySQL Workbench, VS Code
Version Control	Git + GitHub
🛠️ Database Schema

The database consists of the following tables:

city

airport

airline

airport_airline

flight

passenger

bookings

ticket

payment

These include:

Primary & Foreign Keys

Cascading rules

Unique constraints

Proper date/time fields

🧾 DDL Statements

All table creation and database setup scripts are available under:

/sql/airport_schema.sql

🧮 DML Statements

Sample data for cities, airports, passengers, flights, tickets, and bookings is included in:

/sql/airport_data.sql

🔍 Queries Implemented

This project includes all DBMS-required query types:

Simple Queries

Update Queries

Delete Queries

Correlated Subqueries

Nested Queries

Located in:

/sql/queries.sql

🧩 Stored Procedures, Functions & Triggers
Stored Procedures

sp_update_flight_schedule

sp_recalculate_price

sp_get_flights_by_airline

sp_update_status

Functions

fn_estimated_revenue

fn_ticket_count

Trigger

trg_ticket_after_insert
→ Automatically creates a payment record when a new ticket is added.

💻 Frontend (Optional)

A simple optional frontend is included for:

Adding new flights

Viewing all flights

Searching passengers

Viewing bookings

Folder:

/frontend/

🧪 How to Run

Clone the repository:

git clone https://github.com/sanjanapulla06/airport-management-system.git


Open MySQL Workbench

Run airport_schema.sql

Run airport_data.sql

Execute queries or test the procedures/triggers

(Optional) Start the frontend

📚 References

MySQL Documentation

PES University DBMS Lab Notes

Classroom Content

W3Schools SQL