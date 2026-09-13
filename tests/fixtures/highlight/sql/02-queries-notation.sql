-- Reports for the quarterly review
-- [!code focus]
WITH absent AS (
  SELECT member_id,
         SUM(hours) AS hours,
         COUNT(*) FILTER (WHERE excused = 'no') AS unexcused
  FROM absence
  WHERE starts_at >= :quarter_start AND ends_at < :quarter_end -- [!code highlight]
  GROUP BY member_id
),
ranked AS (
  SELECT m.team_id,
         m.last_name || ', ' || m.first_name AS name,
         a.hours,
         RANK() OVER (PARTITION BY m.team_id ORDER BY a.hours DESC NULLS LAST) AS place,
         AVG(a.hours) OVER w AS team_average,
         LAG(a.hours, 1, 0) OVER (ORDER BY a.hours) AS previous
  FROM member m
  INNER JOIN absent a ON a.member_id = m.id
  WINDOW w AS (PARTITION BY m.team_id)
)
SELECT t.label,
       r.name,
       r.hours,
       ROUND(r.team_average, 2) AS average,
       CASE
         WHEN r.hours > 3 * r.team_average THEN 'notable'
         WHEN r.hours BETWEEN 1.5 * r.team_average AND 3 * r.team_average THEN 'watch'
         ELSE 'normal'
       END AS rating
FROM ranked r
JOIN team t ON t.id = r.team_id
WHERE r.place <= 3
ORDER BY t.level, t.label, r.place;

-- [!code word:member]
UPDATE member SET active = FALSE WHERE id = ?;
DELETE FROM member WHERE active = 0; -- [!code --]
DELETE FROM member WHERE active = 0 AND created_at < NOW() - INTERVAL 7 YEAR; -- [!code ++]

-- [!code highlight:3]
INSERT INTO absence (member_id, starts_at, ends_at, excused, note)
VALUES (?, ?, ?, 'open', 'Doctor; partner called in'),
       (:id, '2026-09-15 07:45:00', '2026-09-15 13:00:00', 'yes', 'O''Brien''s note');

select m.first_name, count(distinct a.id) as total -- [!code word:total:2]
from member m left outer join absence a using (id)
where m.last_name like 'M%' and m.last_name not in ('Muñoz', 'Martín')
group by m.first_name having count(*) > 0
limit 10 offset 20;

SELECT 42, -7, 3.14, .5, 1e3, 2.5E-4, 0x1F, X'4F4B', B'1010', TRUE, NULL, '' AS empty, 'Tab\tno escape' AS s;
