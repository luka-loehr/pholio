-- Reports for the admins
DELIMITER //
CREATE PROCEDURE certificate_list(IN p_team VARCHAR(8), OUT p_count INT)
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_name VARCHAR(200);
  DECLARE cur CURSOR FOR
    SELECT CONCAT(last_name, ', ', first_name) FROM member m
    JOIN team t ON t.id = m.team_id
    WHERE t.label = p_team;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  SET p_count = 0;
  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_name;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;
    SET p_count = p_count + 1;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;

CALL certificate_list('blue', @total);
SELECT @total AS "Certificate count";

SELECT t.label,
       SUM(CASE WHEN a.excused = 'yes' THEN a.hours ELSE 0 END) AS excused,
       SUM(CASE a.excused WHEN 'no' THEN a.hours ELSE 0 END) AS unexcused,
       COALESCE(MAX(a.ends_at), 'never') AS latest
FROM team t
LEFT JOIN member m ON m.team_id = t.id
LEFT JOIN absence a ON a.member_id = m.id
WHERE t.edition = '2026/27'
GROUP BY t.label WITH ROLLUP;

EXPLAIN ANALYZE SELECT * FROM v_absent_hours WHERE hours_total > 20;

TRUNCATE TABLE tmp_import;
SELECT 'She said: ''Hello''' AS quote, 'a\'b' AS backslash_escape;

/* Open items:
   - archive old editions
   - export for the yearly statistics
   This block comment is deliberately never closed