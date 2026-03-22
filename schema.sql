CREATE TABLE IF NOT EXISTS watch_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    title VARCHAR(255) NULL,
    proposed_by VARCHAR(255) NOT NULL,
    proposed_by_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watch_votes (
    proposal_id BIGINT UNSIGNED NOT NULL,
    voter_id VARCHAR(255) NOT NULL,
    PRIMARY KEY (proposal_id, voter_id),
    FOREIGN KEY (proposal_id) REFERENCES watch_proposals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
