USE andtr_member4;
START TRANSACTION;
UPDATE users SET name = 'John Smith'
WHERE id = 1 AND email = 'employee@example.test' AND name = 'Demo Employee';
UPDATE users SET name = 'Riverside Medical Centre'
WHERE id = 3 AND email = 'customer2@example.test' AND name = 'Demo Second Customer';
UPDATE notices
SET reference = 'ANDTR-001', details = 'Review the recorded overheating incident and contact the equipment team.'
WHERE id = 1 AND reference = 'DEMO-001';
UPDATE notices
SET reference = 'ANDTR-002', details = 'Confirm the software version with the equipment team.'
WHERE id = 2 AND reference = 'DEMO-002';
COMMIT;
